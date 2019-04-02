<?php

class DB {
	var $last_query;
	var $last_error;
	var $query_log;
	var $config;

	function __construct( $config ) {
		list ( $host, $port ) = explode( ':', ( isset( $config['dbhost'] ) ? $config['dbhost'] : 'dbhost' ) . ":3306" );
		$this->config = array(
			'host' => $host,
			'port' => $port,
			'name' => isset( $config['dbname'] ) ? $config['dbname'] : 'dbname',
			'user' => isset( $config['dbuser'] ) ? $config['dbuser'] : 'dbuser',
			'pass' => isset( $config['dbpass'] ) ? $config['dbpass'] : 'dbpass',
		);
		$this->connect();
	}

	function connect() {
		static $pdo;
		if ( ! $pdo ) {
			try {
				$pdo = new PDO( "mysql:dbname=" . $this->config['name'] . ";host=" . $this->config['host'] . ";port=" . $this->config['port'] . ";charset=utf8mb4", $this->config['user'], $this->config['pass'] );

			} catch ( Exception $e ) {
				die( "Sorry, cannot connect; " . $e->getMessage() );
			}
		}

		return $pdo;
	}


	function list_all( $table, $id_key = false ) {
		return $this->find( $table, array(), - 1, $id_key );
	}

	function parse_table( $table, $all_as_default = false ) {
		list( $table, $fields ) = explode( '[', $table . '[' );
		$fields = trim( $fields, ']' );
		list( $table, $alias ) = explode( ' ', $table . ' ' );
		$_alias = $alias ?: $table;
		if ( ! $fields && $all_as_default ) {
			$fields = '*';
		}
		$fields = implode( ',', array_map( function ( $el ) use ( $_alias ) {
			return $_alias . '.' . trim( $el );
		}, array_filter( explode( ',', $fields ) ) ) );

		return array( $table, $alias, $fields );
	}

	function find( $table, $criteria, $limit = null, $id_key = false, $order = null ) {
		$data = array();

		// error_log("Find: ". json_encode(func_get_args()));

		$tablescan = $criteria == '*tablescan*';
		if ( $tablescan ) {
			$criteria = array();
		}

		if ( is_numeric( $criteria ) ) {
			$criteria = array( 'id' => $criteria );
			$limit    = 1;
		}

		if ( ! is_array( $criteria ) ) {
			return false;
		}

		$_limit = '';
		if ( $limit > 0 ) {
			$_limit = " LIMIT $limit";
		}

		if ( count( $criteria ) > 0 ) {
			foreach ( $criteria as $field => $value ) {
				$_field = str_replace( '.', '`.`', trim( $field ) );
				$op     = '=';
				if ( is_array( $value ) && in_array( reset( $value ), array( '>', '<', '<=', '>=', '<>' ) ) ) {
					$op    = array_shift( $value );
					$value = array_shift( $value );
				}

				if ( is_array( $value ) ) {
					if ( reset( $value ) === 'LIKE' ) {
						array_shift( $value );
						$op = 'LIKE';
						foreach ( $value as $v ) {
							$data[] = $v;
						}
						$value = implode( " OR `{$_field}` {$op} ", array_fill( 0, count( $value ), '?' ) );
					} elseif ( reset( $value ) === 'OR' ) {
						array_shift( $value );
						foreach ( $value as $i => $v ) {
							if ( 'IS NULL' == $v ) {
								$value[ $i ] = " OR `{$_field}` IS NULL ";
							} else {
								$data[]      = $v;
								$value[ $i ] = " OR `{$_field}` = ? ";
							}
						}
						$value = implode( '', $value );
						$value = 'NULL ' . $value;
					} else {
						$op = 'IN';
						foreach ( $value as $v ) {
							$data[] = $v;
						}
						$value = "(" . implode( ", ", array_fill( 0, count( $value ), '?' ) ) . ")";
					}
				} else {
					$data[] = $value;
					$value  = "?";
				}
				$criteria[ $field ] = "(`{$_field}` $op $value)";
			}
			$criteria = implode( " AND ", $criteria );
		} else {
			$criteria = '1';
		}

		$joins = explode( '+', $table );
		$table = array_shift( $joins );

		list( $table, $alias, $fields ) = $this->parse_table( $table, true );

		$_joins = '';

		foreach ( $joins as $join ) {
			$constraints = explode( '(', $join );
			$jtable      = array_shift( $constraints );
			switch ( substr( $jtable, 0, 1 ) ) {

				case '<':
					$jtype = 'LEFT';
					break;
				case '>':
					$jtype = 'RIGHT';
					break;
				case '!':
					$jtype = 'OUTER';
					break;
				default:
					$jtype = 'INNER';
					break;

			}
			$jtable = ltrim( $jtable, '<>!' );

			foreach ( $constraints as &$constraint ) {
				$constraint = trim( $constraint, ')' );
			}

			$constraints = "(" . implode( ") AND (", $constraints ) . ")";
			list ( $jtable, $jalias, $jfields ) = $this->parse_table( $jtable );
			if ( $jfields ) {
				$fields .= ', ' . $jfields;
			}

			$_joins .= " $jtype JOIN $jtable $jalias ON $constraints ";
		}

		$query = "SELECT $fields FROM $table $alias $_joins WHERE $criteria $_limit";
		if ( $order ) {
			$query .= " ORDER BY $order";
		}

		$result = $this->query( $query, $data );
		$list   = array();

		while ( $row = $result->fetchObject() ) {
			if ( false === $id_key ) {
				$list[] = $row;
			}
			else {
				$list[ $row->$id_key ] = $row;
			}
		}

		return ( $limit == 1 ? reset( $list ) : $list );
	}

	function get_col( $table, $criteria, $col=0, $limit=null, $id_key = 'id', $order = null) {
		$records = $this->find( $table, $criteria, $limit, $id_key, $order );
		if (is_numeric($col)) {
			$column = array_map(function($record) use ($col) {
				$record = array_values((array)$record);
				return $record[$col];
			}, $records);
		}
		else {
			$column = array_map(function($record) use ($col) {
				return $record->{$col};
			}, $records);
		}

		return $column;
	}

	function get_var( $table, $criteria, $column, $order = null ) {
		$record = $this->find( $table, $criteria, 1, $column, $order );
		return $record->{$column};
	}

	function get_fields($table, $id_key='Field') {
		$result = $this->query("SHOW COLUMNS IN `$table`");
		$list   = array();

		while ( $row = $result->fetchObject() ) {
			if ( false === $id_key ) {
				$list[] = $row;
			}
			else {
				$list[ $row->$id_key ] = $row;
			}
		}

		$keys = array_map(function($record) use ($id_key) {
			if (!isset($record->$id_key)) {
				$keys = array_keys((array)$record);
				$id_key = reset($keys);
			}

			return $record->$id_key;
		}, $list);

		return array_combine($keys, $list);
	}

	function map_data($table, $data) {
		$keys = $this->get_fields($table);
		$keys = array_keys($keys);

		$data = array_map(function($row) use ($keys) {
			$o = false;
			if (is_object($row)) {
				$o = true;
				$row = (array)$row;
			}
			$_keys = array_keys($row);
			$_common_keys = array_intersect($_keys, $keys);
			$new_row = array();
			foreach ($_common_keys as $key) {
				$new_row[ $key ] = $row[ $key ];
			}

			if($o) {
				$new_row = (object)$new_row;
			}

			return $new_row;
		}, $data);

		return $data;
	}

	function query( $query, $args = null ) {
		$this->last_error = 'Ok';
		if ( ! $args ) {
			$args = array();
		}

		if ( ! is_array( $args ) ) {
			$args = func_get_args();
			array_shift( $args );
		}

		if ( is_array( $args ) ) {
			$_q = @vsprintf( $query, $args );
			if ( ! $_q || $query == $_q ) {
				// vsprintf failed or did not alter the query, assume query is PDO formatted
				$stm              = $this->connect()->prepare( $query );
				$this->last_query = vsprintf( str_replace( "?", "'%s'", $query ), array_map( function ( $el ) {
					return str_replace( "'", "''", $el );
				}, $args ) );
				try {
					// print '<br />'. $this->last_query;
					$stm->execute( $args );
				} catch ( Exception $e ) {
					$this->last_error = $e->getMessage();
					// print '<br />'. $this->last_error;
				}
			} else {
				// poor man's escape
				$args             = array_map( function ( $in ) {
					return str_replace( "'", "''", $in );
				}, $args );
				$_q               = @vsprintf( $query, $args );
				$stm              = $this->connect()->prepare( $_q );
				$this->last_query = "old query $_q";
				try {
					// print '<br />'. $this->last_query;
					$stm->execute();
				} catch ( Exception $e ) {
					$this->last_error = $e->getMessage();
					// print '<br />'. $this->last_error;
				}
			}
		}

		$this->query_log[ $this->last_query ] = $this->last_error;
		// error_log("Query: {$this->last_query}");
		// error_log("Error: {$this->last_error}");
		return $this->last_error != 'Ok' ? false : $stm;
	}

	function create( $table, $field_values ) {
		$field_values['created'] = $field_values['updated'] = date( 'Y-m-d H:i:s' );

		return $this->insert( $table, $field_values );
	}

	function insert( $table, $field_values ) {
		$query  = "INSERT INTO $table (`" . implode( '`, `', array_keys( $field_values ) ) . "`) VALUES (" . implode( ", ", array_fill( 0, count( $field_values ), '?' ) ) . ")";
		$result = $this->query( $query, array_values( $field_values ) );
		$id     = $this->connect()->lastInsertId();

		return $this->find( $table, $id );
	}

	function replace( $table, $id, $field_values ) {
		if ( ! is_numeric( $id ) || ! $id ) {
			$id = array_key_exists( 'id', $field_values ) ? $field_values['id'] : false;
		}

		if ( array_key_exists( 'id', $field_values ) ) {
			unset( $field_values['id'] );
		}

		if ( ! is_numeric( $id ) || ! $id ) {
			$id = $this->find( $table, $id, 1 );
			$id = $id ? $id->id : false;
		}

		if ( $id ) {
			$return = $this->update( $table, $id, $field_values );
		} else {
			$return = $this->create( $table, $field_values );
		}

		return $return;
	}

	function update( $table, $id/** where clause */, $field_values /* new values */ ) {
		if ( ! is_numeric( $id ) ) {
			$id = $this->find( $table, $id, 1 );
			$id = $id ? $id->id : false;
		}

		$field_values['updated'] = date( 'Y-m-d H:i:s' );

		$_field_values = array();
		foreach ( $field_values as $field => $value ) {
			$_field_values[] = "`$field`= ?";
		}

		$_field_values = implode( ', ', $_field_values );

		$id    = $id * 1;
		$query = "UPDATE $table SET $_field_values WHERE id = $id";

		$result = $this->query( $query, array_values( $field_values ) );

		return $this->find( $table, $id );
	}

	function delete( $table, $id_or_field_values ) {
		if ( is_numeric( $id_or_field_values ) ) {
			return $this->delete_by_id( $table, $id_or_field_values );
		} else {
			return $this->delete_by_query( $table, $id_or_field_values );
		}
	}

	function delete_by_id( $table, $id ) {
		$id     = $id * 1;
		$query  = "DELETE FROM $table WHERE id = ?";
		$result = $this->query( $query, $id );

		return true;
	}

	function delete_by_query( $table, $field_values ) {
		$search = $this->find( $table, $field_values );
		foreach ( $search as $result ) {
			$this->delete_by_id( $table, $result->id );
		}

		return true;
	}

	public static function error_log( $logline = null, $conditionalline = null ) {
		static $loglines;
		if ( ! $loglines ) {
			$loglines = array();
		}
		if ( $logline ) {
			if ( $conditionalline ) {
				$loglines[] = $conditionalline;
			}
			$loglines[] = $logline;
		}

		return $loglines;
	}

}
