CREATE OR REPLACE FUNCTION public.epcc_guard_pack_notification_link()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE n inspection_notifications%ROWTYPE; pack_company uuid; owner_company uuid;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('pack-notice:' || NEW.test_pack_id::text, 0));

  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'PACK_NOTICE_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.linked_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'PACK_NOTICE_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  -- Whose claim it is: the pack's own company. Who owns the holds: the
  -- employer. Nobody else names an inspection against this pack.
  SELECT p.company_id INTO pack_company
    FROM piping_test_packs p WHERE p.id = NEW.test_pack_id AND p.project_id = NEW.project_id;
  IF pack_company IS NULL THEN
    RAISE EXCEPTION 'PACK_NOTICE_PACK_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  SELECT pr.owner_company_id INTO owner_company FROM projects pr WHERE pr.id = NEW.project_id;
  IF NEW.company_id IS DISTINCT FROM pack_company AND NEW.company_id IS DISTINCT FROM owner_company THEN
    RAISE EXCEPTION 'PACK_NOTICE_NOT_YOURS' USING ERRCODE = '42501';
  END IF;

  SELECT * INTO n FROM inspection_notifications
   WHERE id = NEW.notification_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    -- RLS already hides notices this company was never party to; say so
    -- rather than letting a missing row look like a missing feature.
    RAISE EXCEPTION 'PACK_NOTICE_NOT_VISIBLE' USING ERRCODE = '42501';
  END IF;
  IF n.state <> 'ISSUED' THEN
    RAISE EXCEPTION 'PACK_NOTICE_NOT_ISSUED' USING ERRCODE = '23514';
  END IF;
  IF EXISTS (SELECT 1 FROM inspection_notification_cancellations c
              WHERE c.notification_id = n.id AND c.project_id = NEW.project_id) THEN
    RAISE EXCEPTION 'PACK_NOTICE_CANCELLED' USING ERRCODE = '23514';
  END IF;

  NEW.linked_at := clock_timestamp();
  RETURN NEW;
END; $function$
