<?php
namespace CommitCap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The guard refused before executing the protected callback. */
final class Unsupported_Transaction_State extends Guard_Error {
}
