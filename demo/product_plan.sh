#!/usr/bin/env bash
# Source this in the product demo and its marker tests. Never evaluate the plan as shell code.
extract_plan_section() {
    local plan="$1" kind="$2" begin end other_begin other_end
    local line body='' state='before'

    case "$kind" in
        install)
            begin='-- BEGIN TRUSTED-ADMIN INSTALL SQL'
            end='-- END TRUSTED-ADMIN INSTALL SQL'
            other_begin='-- BEGIN TRUSTED-ADMIN VERIFICATION SCRIPT'
            other_end='-- END TRUSTED-ADMIN VERIFICATION SCRIPT'
            ;;
        verify)
            begin='-- BEGIN TRUSTED-ADMIN VERIFICATION SCRIPT'
            end='-- END TRUSTED-ADMIN VERIFICATION SCRIPT'
            other_begin='-- BEGIN TRUSTED-ADMIN INSTALL SQL'
            other_end='-- END TRUSTED-ADMIN INSTALL SQL'
            ;;
        *) printf 'Invalid product plan section: %s\n' "$kind" >&2; return 1 ;;
    esac

    while IFS= read -r line || [[ -n "$line" ]]; do
        if [[ "$line" == "$begin" ]]; then
            [[ "$state" == before ]] || { printf 'Duplicate or misplaced %s start marker\n' "$kind" >&2; return 1; }
            state='inside'
        elif [[ "$line" == "$end" ]]; then
            [[ "$state" == inside ]] || { printf 'Duplicate or misplaced %s end marker\n' "$kind" >&2; return 1; }
            state='after'
        elif [[ "$line" == '-- BEGIN TRUSTED-ADMIN '* || "$line" == '-- END TRUSTED-ADMIN '* ]]; then
            if [[ "$line" != "$other_begin" && "$line" != "$other_end" ]] || [[ "$state" == inside ]]; then
                printf 'Unexpected marker in %s plan section: %s\n' "$kind" "$line" >&2
                return 1
            fi
        elif [[ "$state" == inside ]]; then
            body+="$line"$'\n'
        fi
    done <<< "$plan"

    [[ "$state" == after && -n "$body" ]] || { printf 'Missing or empty %s plan section\n' "$kind" >&2; return 1; }
    printf '%s' "$body"
}
