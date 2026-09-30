<?php
// Browser-close simulation: one request creates, approves and queues a job,
// then the process exits. No request-local object survives into later workers.
$spec = json_decode( file_get_contents( $args[0] ), true );
if ( ! is_array( $spec ) || empty( $spec['ids'] ) || ! isset( $spec['result'], $spec['plan'] ) ) {
	throw new RuntimeException( 'admin-request spec invalid' );
}
wp_set_current_user( (int) $spec['actor'] );
$plan = WriteLeash\Woo_Price_Planner::preview(
	WriteLeash\Price_Selection_Spec::ids( array_map( 'intval', $spec['ids'] ) ),
	new WriteLeash\Price_Operation( WriteLeash\Price_Operation::SET, (string) $spec['target'] ),
	new WriteLeash\Safety_Policy( 50, '100', '100', true, '100' )
);
$job = WriteLeash\Job_Repository::create_from_plan( $plan, (int) $spec['actor'] );
$job = WriteLeash\Job_Repository::approve( (int) $job['id'], (int) $spec['actor'] );
$queue = WriteLeash\Job_Worker::queue_job( (int) $job['id'] );
file_put_contents( $spec['plan'], serialize( $plan ) );
file_put_contents( $spec['result'], json_encode( array( 'job_id' => (int) $job['id'], 'status' => $job['status'], 'queue' => $queue, 'plan_id' => $plan->data()['plan_id'] ) ) );
echo "admin-request-complete\n";
