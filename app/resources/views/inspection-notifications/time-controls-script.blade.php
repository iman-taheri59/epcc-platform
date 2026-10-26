<style>[data-time-helper][hidden],[data-time-mode][hidden]{display:none!important}[data-time-exact][readonly]{background:#f1f5f9;color:#334155}[data-time-preview]{white-space:pre-wrap}</style>
<script>
(() => {
    'use strict';
    const messages = {{ Illuminate\Support\Js::from(['invalid'=>__('inspection_notifications.time_invalid'),'incomplete'=>__('inspection_notifications.time_incomplete'),'empty'=>__('inspection_notifications.filter_time_empty')]) }};
    const probe = document.createElement('input');
    probe.type = 'date';
    if (probe.type !== 'date') return;
    probe.type = 'time';
    if (probe.type !== 'time') return;

    // Parse numeric Gregorian components only. Never use the host's time zone,
    // Date(string), toISOString(), or an inferred offset to serialize user input.
    function parts(value) {
        const m = /^(?!0000)(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})([+-])(\d{2}):(\d{2})$/.exec(value);
        if (!m) return null;
        const y=Number(m[1]), month=Number(m[2]), day=Number(m[3]);
        const leap = y%4===0 && (y%100!==0 || y%400===0);
        const days=[31,leap?29:28,31,30,31,30,31,31,30,31,30,31];
        if (month<1 || month>12 || day<1 || day>days[month-1] || Number(m[4])>23 || Number(m[5])>59 || Number(m[6])>59 || Number(m[8])>15 || Number(m[9])>59) return null;
        const offset=m[7]+m[8]+':'+m[9];
        if (offset==='-00:00') return null;
        return {date:m[1]+'-'+m[2]+'-'+m[3], clock:m[4]+':'+m[5]+':'+m[6], offset};
    }

    document.querySelectorAll('[data-notification-time]').forEach(widget => {
        const exact=widget.querySelector('[data-time-exact]');
        const date=widget.querySelector('[data-time-date]');
        const clock=widget.querySelector('[data-time-clock]');
        const offset=widget.querySelector('[data-time-offset]');
        const manual=widget.querySelector('[data-time-manual]');
        const preview=widget.querySelector('[data-time-preview]');
        const controls=[date,clock,offset];
        const optional=widget.hasAttribute('data-time-optional');
        if (!exact || !date || !clock || !offset || !manual || !preview) return;
        widget.querySelector('[data-time-helper]').hidden=false;
        widget.querySelector('[data-time-mode]').hidden=false;

        function show(value) {
            const p=parts(value);
            preview.textContent=p ? p.date+'  '+p.clock+'  UTC'+p.offset : (value ? messages.invalid : (optional && !controls.some(c => c.value) ? messages.empty : messages.incomplete));
            preview.dir=p ? 'ltr' : document.documentElement.dir;
            return p;
        }
        function load() {
            const p=parts(exact.value);
            date.value=p ? p.date : '';
            clock.value=p ? p.clock : '';
            offset.value=p ? p.offset : '';
            show(exact.value);
        }
        function requirements() {
            const any=controls.some(c => c.value !== '');
            controls.forEach(c => { c.required=!manual.checked && (!optional || any); });
        }
        function applyMode() {
            exact.readOnly=!manual.checked;
            exact.setCustomValidity('');
            controls.forEach(c => {c.disabled=manual.checked; c.required=false; c.setCustomValidity('');});
            load();
            requirements();
            if (manual.checked && exact.value && !parts(exact.value)) exact.setCustomValidity(messages.invalid);
        }
        function compose() {
            let time=clock.value;
            if (/^\d{2}:\d{2}$/.test(time)) time+=':00';
            const candidate=date.value+'T'+time+offset.value;
            const p=parts(candidate);
            // Clearing or partially editing a helper must never send the previous
            // complete range. Required helper controls gate readonly exact input.
            exact.value=p ? candidate : '';
            requirements();
            offset.setCustomValidity(offset.value && !/^[+-]\d{2}:\d{2}$/.test(offset.value) ? messages.invalid : '');
            if (date.value && time && offset.value && !p) offset.setCustomValidity(messages.invalid);
            show(exact.value);
        }
        controls.forEach(c => c.addEventListener('input',compose));
        controls.forEach(c => c.addEventListener('change',compose));
        exact.addEventListener('input',() => {
            load();
            exact.setCustomValidity(exact.value && !parts(exact.value) ? messages.invalid : '');
        });
        manual.addEventListener('change',applyMode);
        // Preserve malformed restored direct input for correction; do not silently
        // normalize it, replace it with a browser local time, or discard it on load.
        manual.checked=Boolean(exact.value && !parts(exact.value));
        applyMode();
    });
})();
</script>
