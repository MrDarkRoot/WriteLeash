#!/usr/bin/env bash
set -euo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
source "$ROOT/demo/product_plan.sh"
fail() { printf 'PRODUCT PLAN TEST FAIL: %s\n' "$1" >&2; exit 1; }
reject() {
    local label="$1" plan="$2" kind="$3" result
    if result="$(extract_plan_section "$plan" "$kind" 2>&1)"; then
        fail "$label unexpectedly extracted [$result]"
    fi
}

plan="$("$ROOT/commitcap" protect-update --table public.cc_demo_test_a --budget 5 --writer-role commitcap_demo_writer)"
install="$(extract_plan_section "$plan" install)"
verify="$(extract_plan_section "$plan" verify)"
[[ "$install" == *'BEFORE UPDATE ON "public"."cc_demo_test_a"'* &&
   "$install" == *"EXECUTE FUNCTION commitcap_native.enforce_rows_updated('5');"* &&
   "$verify" == *'\set cc_table_name cc_demo_test_a'* &&
   "$verify" == *'\set cc_expected_budget 5'* &&
   "$verify" == *'\set cc_writer_role commitcap_demo_writer'* &&
   "$verify" == *'protected writer cannot change session_replication_role'* ]] || fail 'extracted sections do not match #27 plan'

install_begin='-- BEGIN TRUSTED-ADMIN INSTALL SQL'
install_end='-- END TRUSTED-ADMIN INSTALL SQL'
verify_begin='-- BEGIN TRUSTED-ADMIN VERIFICATION SCRIPT'
verify_end='-- END TRUSTED-ADMIN VERIFICATION SCRIPT'
reject 'missing install start' "${plan/"$install_begin"/}" install
reject 'missing install end' "${plan/"$install_end"/}" install
reject 'duplicate install start' "${plan/"$install_begin"/"$install_begin"$'\n'"$install_begin"}" install
reject 'duplicate install end' "${plan/"$install_end"/"$install_end"$'\n'"$install_end"}" install
reject 'malformed install marker' "${plan/"$install_begin"/"$install_begin" extra}" install
reject 'empty install section' "${plan/"$install_begin"$'\n'"$install"$'\n'"$install_end"/"$install_begin"$'\n'"$install_end"}" install
reject 'missing verification start' "${plan/"$verify_begin"/}" verify
reject 'missing verification end' "${plan/"$verify_end"/}" verify
reject 'duplicate verification start' "${plan/"$verify_begin"/"$verify_begin"$'\n'"$verify_begin"}" verify
reject 'duplicate verification end' "${plan/"$verify_end"/"$verify_end"$'\n'"$verify_end"}" verify
reject 'malformed verification marker' "${plan/"$verify_end"/"$verify_end" extra}" verify
reject 'unexpected nested marker' "${plan/"$install_begin"/"$install_begin"$'\n'"$verify_begin"}" install
reject 'unknown section name' "$plan" unknown

printf 'Product demo plan markers: PASS\n'
