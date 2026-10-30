CREATE OR REPLACE FUNCTION public.epcc_guard_test_pack_transition()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE latest text; n integer; notice record; hold_state text;
BEGIN
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'PACK_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  -- An accepted pack is a handover artefact. Reopening it silently would let a
  -- completed dossier change under the client who already signed for it.
  IF OLD.state = 'ACCEPTED' THEN
    RAISE EXCEPTION 'PACK_ACCEPTED_TERMINAL' USING ERRCODE = '23514';
  END IF;
  -- Metadata-only edits (notes, titles, planned pressure) are not claims.
  IF NEW.state IS NOT DISTINCT FROM OLD.state THEN
    RETURN NEW;
  END IF;
  IF NOT ((OLD.state = 'DRAFT' AND NEW.state = 'READY')
       OR (OLD.state = 'READY' AND NEW.state = 'TESTED')
       OR (OLD.state = 'TESTED' AND NEW.state = 'ACCEPTED')) THEN
    RAISE EXCEPTION 'PACK_INVALID_TRANSITION' USING ERRCODE = '23514';
  END IF;

  -- A hold point on a notice about this pack stops the claim. Only 'H': a
  -- witness, review or monitor point records that work was observed, it does
  -- not license stopping it.
  IF NEW.state IN ('TESTED', 'ACCEPTED') THEN
    FOR notice IN
      SELECT nn.id AS nid
        FROM piping_test_pack_notifications ln
        JOIN inspection_notifications nn ON nn.id = ln.notification_id AND nn.project_id = NEW.project_id
        JOIN inspection_itp_stages st ON st.id = nn.stage_id AND st.project_id = NEW.project_id
       WHERE ln.project_id = NEW.project_id AND ln.test_pack_id = NEW.id
         AND st.point = 'H'
       ORDER BY nn.number
    LOOP
      hold_state := epcc_pack_notice_state(NEW.project_id, notice.nid);
      -- Only a hold that is genuinely uncleared stops the work. A cancelled
      -- notice was withdrawn and a draft is not yet a request, so neither is
      -- a stop: the completeness report reads the same two states as gaps and
      -- nothing else, and the two must never disagree.
      IF hold_state = 'REJECTED' THEN
        RAISE EXCEPTION 'PACK_HOLD_REJECTED' USING ERRCODE = '23514';
      ELSIF hold_state = 'NOT_SIGNED' THEN
        RAISE EXCEPTION 'PACK_HOLD_NOT_SIGNED' USING ERRCODE = '23514';
      END IF;
    END LOOP;
  END IF;

  IF NEW.state = 'TESTED' THEN
    SELECT count(*) INTO n FROM test_pack_results r
     WHERE r.project_id = NEW.project_id AND r.test_pack_id = NEW.id;
    IF n = 0 THEN
      RAISE EXCEPTION 'PACK_TESTED_WITHOUT_RESULT' USING ERRCODE = '23514';
    END IF;
  END IF;
  IF NEW.state = 'ACCEPTED' THEN
    -- Latest recorded result decides, so FAIL -> repair -> PASS lineage can
    -- culminate in acceptance, but a lone FAIL never can.
    SELECT r.result INTO latest FROM test_pack_results r
     WHERE r.project_id = NEW.project_id AND r.test_pack_id = NEW.id
     ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1;
    IF latest IS NULL OR latest <> 'PASS' THEN
      RAISE EXCEPTION 'PACK_ACCEPTED_WITHOUT_PASS' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END; $function$
