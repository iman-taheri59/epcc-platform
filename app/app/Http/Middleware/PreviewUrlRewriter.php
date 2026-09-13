<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attach the signed session token to everything the browser will follow.
 *
 * PreviewSessionFallback can only recover a session if the token reaches it.
 * A cookie is attached to every request automatically; a query parameter is
 * not, so it has to be written into each outgoing link by hand:
 *
 *   - Location headers, or login's 302 loses the session it just created;
 *   - href / src / action, or the first click logs the user out;
 *   - a hidden field in every form, because a form's action may carry no
 *     query string and POST bodies are what actually arrive;
 *   - Livewire's data-update-uri, so component round-trips stay authenticated;
 *   - the `redirect` member of JSON responses, which Livewire follows in JS;
 *   - and links injected into the DOM later, which no server-side rewrite can
 *     ever see - handled by a small inline script.
 *
 * THE INLINE SCRIPT MUST OBSERVE childList ONLY. Observing attributes means
 * every href this script rewrites fires the observer again: infinite loop,
 * pegged CPU, white page.
 *
 * Runs last in the `web` group, i.e. first on the way back out, so it sees the
 * response the application actually produced. Preview hosts only.
 */
final class PreviewUrlRewriter
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($request, $next($request));
    }

    /**
     * Attach the token to a finished response.
     *
     * Static because the middleware is NOT the only entry point. An exception
     * unwinds the whole pipeline, so a response rendered from one - the auth
     * redirect to /login, a ValidationException redirecting back, the 419
     * handler - is produced OUTSIDE every middleware and would silently lose
     * the token. bootstrap/app.php calls this from $exceptions->respond() to
     * cover exactly those paths. Idempotent: withToken() skips URLs that
     * already carry the parameter.
     */
    public static function apply(Request $request, Response $response): Response
    {
        if (! PreviewSessionFallback::enabled() || ! $request->hasSession()) {
            return $response;
        }

        $self = new self;
        $token = PreviewSessionFallback::token($request->session()->getId());

        if ($response->isRedirection()) {
            $response->headers->set(
                'Location',
                $self->withToken((string) $response->headers->get('Location'), $token),
            );

            return $response;
        }

        $type = (string) $response->headers->get('Content-Type');

        if (str_contains($type, 'text/html')) {
            $response->setContent($self->rewriteHtml((string) $response->getContent(), $token));
        } elseif (str_contains($type, 'json')) {
            $response->setContent($self->rewriteJson((string) $response->getContent(), $token));
        }

        return $response;
    }

    /** Append the token to a same-app URL, never to another origin. */
    private function withToken(string $url, string $token): string
    {
        $param = PreviewSessionFallback::PARAM;

        if ($url === '' || str_contains($url, $param.'=') || str_starts_with($url, '#')) {
            return $url;
        }

        // data:, mailto:, javascript: and friends are not navigations.
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1 && ! preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if ($host !== null && ! ConfigureForPreviewProxy::isPreviewHost($host)) {
            return $url;
        }

        // Keep the fragment last: /x?sid=…#frag, not /x#frag?sid=…
        $fragment = '';

        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $sep = str_contains($url, '?') ? '&' : '?';

        return $url.$sep.$param.'='.$token.$fragment;
    }

    private function rewriteHtml(string $html, string $token): string
    {
        $param = PreviewSessionFallback::PARAM;

        // href/src/action on same-app URLs. Livewire's update endpoint is an
        // attribute too, so it is matched in the same pass.
        $html = (string) preg_replace_callback(
            '/\b(href|action|src|data-update-uri)="([^"]*)"/i',
            fn (array $m): string => $m[1].'="'.$this->withToken($m[2], $token).'"',
            $html,
        );

        // Every form gets the token in its body: an action without a query
        // string would otherwise drop it, and GET forms rebuild the query
        // from their fields.
        $html = (string) preg_replace(
            '/(<form\b[^>]*>)/i',
            '$1<input type="hidden" name="'.$param.'" value="'.$token.'">',
            $html,
        );

        return $this->injectScript($html, $token);
    }

    /** Livewire and friends redirect in JS via a `redirect` member. */
    private function rewriteJson(string $json, string $token): string
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['redirect']) || ! is_string($data['redirect'])) {
            return $json;
        }

        $data['redirect'] = $this->withToken($data['redirect'], $token);

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Patch links added to the DOM after the response was rendered.
     *
     * childList only - see the class docblock. Also intercepts fetch() and
     * XHR so component round-trips keep their session.
     */
    private function injectScript(string $html, string $token): string
    {
        if (! str_contains($html, '<head')) {
            return $html;
        }

        $param = PreviewSessionFallback::PARAM;

        $script = <<<HTML
<script>(function(){
  var P=%PARAM%,T=%TOKEN%;
  function tag(u){
    if(!u||u[0]==='#'||u.indexOf(P+'=')>-1)return u;
    if(/^[a-z][a-z0-9+.-]*:/i.test(u)&&!/^https?:\\/\\//i.test(u))return u;
    try{if(/^https?:/i.test(u)&&new URL(u).host!==location.host)return u;}catch(e){return u;}
    var h='',i=u.indexOf('#');if(i>-1){h=u.slice(i);u=u.slice(0,i);}
    return u+(u.indexOf('?')>-1?'&':'?')+P+'='+T+h;
  }
  function scan(root){
    if(!root||root.nodeType!==1)return;
    var els=root.querySelectorAll?root.querySelectorAll('[href],[src],[action],[data-update-uri]'):[];
    for(var i=0;i<els.length;i++)patch(els[i]);
    patch(root);
    var fs=root.querySelectorAll?root.querySelectorAll('form'):[];
    for(var j=0;j<fs.length;j++)field(fs[j]);
    // querySelectorAll never returns the root itself, so a <form> inserted as
    // the top-level node would keep its action but lose the hidden field.
    field(root);
  }
  function patch(el){
    if(!el.getAttribute)return;
    ['href','src','action','data-update-uri'].forEach(function(a){
      var v=el.getAttribute(a);if(v==null)return;var n=tag(v);if(n!==v)el.setAttribute(a,n);
    });
  }
  function field(f){
    if(f.tagName!=='FORM'||f.querySelector('input[name="'+P+'"]'))return;
    var i=document.createElement('input');i.type='hidden';i.name=P;i.value=T;f.appendChild(i);
  }
  // childList ONLY. Watching attributes re-triggers on our own writes:
  // infinite loop, white page.
  new MutationObserver(function(ms){
    for(var i=0;i<ms.length;i++)for(var j=0;j<ms[i].addedNodes.length;j++)scan(ms[i].addedNodes[j]);
  }).observe(document.documentElement,{childList:true,subtree:true});
  var of=window.fetch;
  if(of)window.fetch=function(r,o){
    try{if(typeof r==='string')r=tag(r);else if(r&&r.url)r=new Request(tag(r.url),r);}catch(e){}
    return of.call(this,r,o);
  };
  var oo=XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open=function(m,u){
    try{u=tag(u);}catch(e){}
    return oo.apply(this,[m,u].concat([].slice.call(arguments,2)));
  };
  document.addEventListener('DOMContentLoaded',function(){scan(document.body);});
})();</script>
HTML;

        $script = str_replace(
            ['%PARAM%', '%TOKEN%'],
            [json_encode($param), json_encode($token)],
            $script,
        );

        return (string) preg_replace('/(<head\b[^>]*>)/i', '$1'.$script, $html, 1);
    }
}
