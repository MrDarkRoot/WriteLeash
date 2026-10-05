<?php
// Repository-only deterministic #107/#121 claim boundary, shared by both audits.
function writeleash_conflict_copy_audit( string $text, bool $frozen = false ): void {
	$text = preg_replace( '/\s+/', ' ', $text );
	// The pinned historical artifact keeps its reviewed wording; the live
	// listing uses the merchant-language wording for the same bounded claim.
	$exact = $frozen
		? 'If the stored regular price or another execution precondition no longer matches the approved plan, that item is reported as a conflict instead of being blindly overwritten.'
		: 'If the stored price WriteLeash is changing or another checked setting no longer matches the preview, WriteLeash leaves the newer value alone and marks that product as a conflict.';
	$caption = $frozen
		? 'A later regular-price or execution-state change becomes a conflict instead of a blind overwrite.'
		: 'A later price or setting change becomes a conflict instead of a blind overwrite.';
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
