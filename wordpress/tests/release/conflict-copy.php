<?php
// Repository-only deterministic #107/#121 claim boundary, shared by both audits.
function writeleash_conflict_copy_audit( string $text ): void {
	$text = preg_replace( '/\s+/', ' ', $text );
	$exact = 'If the stored regular price or another execution precondition no longer matches the approved plan, that item is reported as a conflict instead of being blindly overwritten.';
	$caption = 'A later regular-price or execution-state change becomes a conflict instead of a blind overwrite.';
	foreach ( array( $exact, $caption ) as $required ) {
		if ( false === strpos( $text, $required ) ) {
			throw new RuntimeException( '#121 conflict copy missing exact bounded wording: ' . $required );
		}
	}
	foreach ( array(
		'/\b(?:any|every|all) (?:later )?product edits?\b/i',
		'/\bif (?:a|the) product (?:is |was )?(?:edited|changed)\b/i',
		'/\ba (?:later )?product (?:edit|edited after approval) becomes? a conflict\b/i',
		'/\blater edits (?:always conflict|are reported as conflicts)\b/i',
		'/\bif (?:a|the) product no longer matches the approved plan\b/i',
	) as $broad ) {
		if ( preg_match( $broad, $text ) ) {
			throw new RuntimeException( '#121 conflict copy broadens provenance-only edits: ' . $broad );
		}
	}
}
