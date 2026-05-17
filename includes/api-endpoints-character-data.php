<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( ! function_exists( 'nw_decode_jsonb_array' ) ) {
    function nw_decode_jsonb_array( $value ): array {
        if ( is_array( $value ) ) {
            return array_values(
                array_filter(
                    array_map(
                        static function ( $item ) {
                            return is_scalar( $item ) ? trim( (string) $item ) : '';
                        },
                        $value
                    )
                )
            );
        }
        if ( is_string( $value ) ) {
            $value = trim( $value );
            if ( '' === $value ) {
                return array();
            }

            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                return array_values(
                    array_filter(
                        array_map(
                            static function ( $item ) {
                                return is_scalar( $item ) ? trim( (string) $item ) : '';
                            },
                            $decoded
                        )
                    )
                );
            }

            return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
        }

        return array();
    }
}
