-- Stable Laravel compatibility inventory. Credentials and relation contents are excluded.
-- Exclude exactly the reviewed subordinate Go tables; integration tests verify their behavior.
SELECT jsonb_pretty(jsonb_build_object(
 'seed_catalog', jsonb_build_object(
   'capabilities', (SELECT jsonb_agg(jsonb_build_object('key',key,'scope',scope) ORDER BY key) FROM capabilities),
   'roles', (SELECT jsonb_agg(jsonb_build_object('key',key,'scope',scope,'built_in',built_in) ORDER BY key) FROM roles),
   'role_capabilities', (SELECT jsonb_agg(jsonb_build_object('role',role_key,'capability',capability_key) ORDER BY role_key,capability_key) FROM role_capabilities)),
 'columns', (SELECT jsonb_agg(to_jsonb(c) ORDER BY table_name,ordinal_position) FROM
   (SELECT table_name,column_name,ordinal_position,data_type,udt_name,is_nullable,column_default
    FROM information_schema.columns WHERE table_schema='public' AND table_name NOT LIKE 'goose_%'
      AND table_name NOT IN ('infrastructure_dispatches','infrastructure_observation_challenges','infrastructure_observation_receipts','infrastructure_dispatch_evidence_challenges','infrastructure_dispatch_evidence_receipts')) c),
 'constraints', (SELECT jsonb_agg(to_jsonb(c) ORDER BY relation,name) FROM
   (SELECT cl.relname relation,co.conname name,pg_get_constraintdef(co.oid) definition
    FROM pg_constraint co JOIN pg_class cl ON cl.oid=co.conrelid JOIN pg_namespace n ON n.oid=cl.relnamespace
    WHERE n.nspname='public' AND cl.relname NOT IN ('infrastructure_dispatches','infrastructure_observation_challenges','infrastructure_observation_receipts','infrastructure_dispatch_evidence_challenges','infrastructure_dispatch_evidence_receipts') AND cl.relname NOT LIKE 'goose_%') c),
 'indexes', (SELECT jsonb_agg(to_jsonb(c) ORDER BY tablename,indexname) FROM
   (SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public'
    AND tablename NOT IN ('infrastructure_dispatches','infrastructure_observation_challenges','infrastructure_observation_receipts','infrastructure_dispatch_evidence_challenges','infrastructure_dispatch_evidence_receipts') AND tablename NOT LIKE 'goose_%') c),
 'triggers', (SELECT jsonb_agg(to_jsonb(c) ORDER BY relation,name) FROM
   (SELECT cl.relname relation,t.tgname name,pg_get_triggerdef(t.oid) definition FROM pg_trigger t
    JOIN pg_class cl ON cl.oid=t.tgrelid JOIN pg_namespace n ON n.oid=cl.relnamespace
    WHERE n.nspname='public' AND NOT t.tgisinternal AND cl.relname NOT IN ('infrastructure_dispatches','infrastructure_observation_challenges','infrastructure_observation_receipts','infrastructure_dispatch_evidence_challenges','infrastructure_dispatch_evidence_receipts')) c),
 'functions', (SELECT jsonb_agg(to_jsonb(c) ORDER BY name) FROM
   (SELECT p.proname name,pg_get_functiondef(p.oid) definition FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
    WHERE n.nspname='public' AND p.prokind='f' AND p.proname NOT IN ('utcp_guard_dispatch_evidence','utcp_guard_receipt_evidence')) c),
 'views', (SELECT jsonb_agg(to_jsonb(c) ORDER BY viewname) FROM (SELECT viewname,definition FROM pg_views WHERE schemaname='public') c),
 'grants', (SELECT jsonb_agg(to_jsonb(c) ORDER BY table_name,grantee,privilege_type) FROM
   (SELECT table_name,grantee,privilege_type FROM information_schema.table_privileges WHERE table_schema='public'
    AND table_name NOT IN ('infrastructure_dispatches','infrastructure_observation_challenges','infrastructure_observation_receipts','infrastructure_dispatch_evidence_challenges','infrastructure_dispatch_evidence_receipts') AND table_name NOT LIKE 'goose_%') c)
));
