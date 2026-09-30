<?php
// #97 test-only identity transformation. Converts canonical WriteLeash
// low-level SQL text into the exact pre-rebrand CommitCap identity so
// adversarial fixtures build a semantically exact old graph.
//
// Finite mapping only; no loose global substitution. Used exclusively by
// old-graph adversarial tests; never by production code.

function cc97_old_identity_sql( string $sql ): string {
	return str_replace(
		array(
			'writeleash_v01_',
			'WriteLeash V0.1 cooperative UPDATE state',
		),
		array(
			'commitcap_v01_',
			'CommitCap V0.1 cooperative UPDATE state',
		),
		$sql
	);
}

function cc97_old_identity_name( string $name ): string {
	return str_replace( 'writeleash_v01_', 'commitcap_v01_', $name );
}
