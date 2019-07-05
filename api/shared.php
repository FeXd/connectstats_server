<?php

/*
 *  ----- Garmin Service API -----
 *  User Registration
 *    - Save user access token and secret
 *  Fit File ping
 *    - start subprocess to get callbackURL
 *    - save to db the data
 *    - try to match to activity
 *    - unique by activityFile Id (or the url)
 *    - summary id seem unrelated ot activity summary id and activityFileId
 *  Activity Summary
 *    - save the summary as json
 *    - try to match any corresponding file
 *    - json contains summaryId that matches garmin connect
 *  Matching fit file and activity
 *    - userId and startTimeInSeconds
 *
 *  ----- Query API -----
 *  Query Info for user (userid)
 *    - min date, max date, number of activities, max activity id
 *  List of Activity for user (userid, number, offset from last)
 *    - from activities select for userid startTimeInSeconds DESC
 *  Query from file for userid, assetId
 */

# include_once( $_SERVER['DOCUMENT_ROOT'].'/php/sql_helper.php' )
include_once( 'sql_helper.php');

class garmin_sql extends sql_helper{
	function __construct() {
        include( 'config.php' );
		parent::__construct( $api_config['database'] );
	}
	static function get_instance() {
		static $instance;
		if( ! isset( $instance ) ){
            include( 'config.php' );
            $instance = new sql_helper( NULL, $api_config['database'] );
		}
		return( $instance );
	}
}

class StatusCollector {
    function __construct( ){
        $this->messages = array();
        $this->table = NULL;
        $this->verbose = false;
    }

    function clear($table){
        $this->table = $table;
        $this->messages = array();
    }
    
    function error( $msg ){
        if( $this->verbose ){
            printf( "ERROR: %s".PHP_EOL, $msg );
        }
        array_push( $this->messages, $msg );
    }

    function success() {
        return( count( $this->messages ) == 0);
    }
    function hasError() {
        return( count( $this->messages ) > 0 );
    }
    
    function record($sql,$rawdata) {
        if(  $sql && $this->table !== NULL ){
            $error_table = sprintf( "error_%s", $this->table );

            if( ! $sql->table_exists( $error_table ) ){
                $sql->create_or_alter( $error_table, array(
                    'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    'json' => 'TEXT',
                    'message' => 'TEXT',
                    'user_agent' => 'TEXT',
                    'remote_addr' => 'TEXT'
                ) );
                                                        
            }
            if( gettype( $rawdata ) == 'array' ){
                $rawdata = json_encode( $rawdata );
            }
            $row = array( 'json' => $rawdata,
                          'message' => implode(', ', $this->messages ),
                          'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                          'remote_addr' => $_SERVER['REMOTE_ADDR'],
            );

            if( ! $sql->insert_or_update( $error_table, $row ) ){
                printf( 'FAILED TO RECORD: %s'.PHP_EOL, $sql->lasterror );
            }
        }else{
            print( "ERRORS".PHP_EOL );
            print_r( $this->messages );
        }
    }
}


class GarminProcess {
    function __construct() {
        $this->sql = new garmin_sql();
        $this->sql->verbose = false;
        $this->verbose = false;
        $this->status = new StatusCollector();
        if( isset($_GET['verbose']) && $_GET['verbose']==1){
            $this->set_verbose( true );
        }

        include( 'config.php' );
        $this->api_config = $api_config;
    }

    function set_verbose($verbose){
        $this->verbose = $verbose;
        $this->sql->verbose = $verbose;
        $this->status->verbose = $verbose;
    }

    // Reset Database schema from scratch 
    function reset_schema() {
        // For development database only
        if( $this->sql->table_exists( 'dev' ) ){
            $tables = array( 'activities', 'assets', 'tokens', 'error_activities', 'error_fitfiles', 'schema', 'users', 'fitfiles', 'backfills' );
            foreach( $tables as $table ){
                $this->sql->execute_query( "DROP TABLE IF EXISTS `$table`" );
            }
            return true;
        }else{
            return false;
        }
    }
    
    function ensure_schema() {
        $schema_version = 3;
        $schema = array(
            "users" => array(
                'cs_user_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'userId' => 'VARCHAR(128)',
                'backfillEndTime' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            "tokens" => array(
                'token_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'userAccessToken' => 'VARCHAR(128)',
                'userId' => 'VARCHAR(128)',
                'userAccessTokenSecret' => 'VARCHAR(128)',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'backfillEndTime' => 'BIGINT(20) UNSIGNED'
            ),
            "activities" =>  array(
                'activity_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'file_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'json' => 'TEXT',
                'startTimeInSeconds' => 'BIGINT(20) UNSIGNED',
                'userId' => 'VARCHAR(128)',
                'userAccessToken' => 'VARCHAR(128)',
                'summaryId' => 'VARCHAR(128)',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            "fitfiles" => array(
                'file_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'activity_id' => 'BIGINT(20) UNSIGNED',
                'asset_id' => 'BIGINT(20) UNSIGNED',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'userId' => 'VARCHAR(128)',
                'userAccessToken' => 'VARCHAR(128)',
                'callbackURL' => 'TEXT',
                'startTimeInSeconds' => 'BIGINT(20) UNSIGNED',
                'summaryId' => 'VARCHAR(128)',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            'assets' => array(
                'asset_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'file_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'tablename' => 'VARCHAR(128)',
                'filename' => 'VARCHAR(32)',
                'path' => 'VARCHAR(128)',
                'data' => 'MEDIUMBLOB',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            'backfills' => array(
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'token_id' => 'BIGINT(20) UNSIGNED',
                'summaryStartTimeInSeconds' => 'BIGINT(20) UNSIGNED',
                'summaryEndTimeInSeconds' => 'BIGINT(20) UNSIGNED',
                'backfillEndTime' => 'BIGINT(20) UNSIGNED'
            )
        );
        $create = false;
        if( ! $this->sql->table_exists('schema') ){
            $create = true;
            $this->sql->create_or_alter('schema', array( 'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'version' => 'BIGINT(20) UNSIGNED' ) );
        }else{
            $r = $this->sql->query_first_row('SELECT MAX(version) AS v FROM `schema`' );
            if( $r['v'] < $schema_version ){
                $create = true;
            }
        }

        if( $create ){
            foreach( $schema as $table => $defs ){
                $this->sql->create_or_alter( $table, $defs );
            }
            $this->sql->insert_or_update('schema', array( 'version' => $schema_version ) );
        }
    }

    function deregister_user(){
        $this->ensure_schema();
        
        $this->status = new StatusCollector($table);

        $end_points_fields = array(
            'userId' => 'VARCHAR(1024)',
        );

        $rawdata = file_get_contents("php://input");
        if( ! $rawdata ){
            $this->status->error('Input from query appears empty' );
        }

        if( $this->status->success() ) {
            $data = json_decode($rawdata,true);
            if( ! $data ) {
                $this->status->error( 'Failed to decode json' );
            }
            foreach( $data as $summary_type => $infos){
                foreach( $infos as $info){
                    if( isset( $info['userAccessToken'] ) ){
                        $user = $this->user_info( $info['userAccessToken'] );
                        $token = $info['userAccessToken'];
                        $query = "UPDATE tokens SET userAccessTokenSecret = NULL WHERE userAccessToken = '$token'";
                        if( ! $this->sql->execute_query( $query ) ){
                            $this->status->error( sprintf( 'Sql failed %s (%s)', $query, $this->sql->lasterror ) );
                        }
                    }
                }
            }
        }
        if( $this->status->hasError() ){
            $this->status->record( 'deregistration', $rawdata );
        }
        return $this->status->success();
    }
    
    function register_user( $userAccessToken, $userAccessTokenSecret ){
        $this->ensure_schema();

        $values = array( 'userAccessToken' => $userAccessToken,
                         'userAccessTokenSecret' => $userAccessTokenSecret
        );

        # See if we already have registered the user
        $user = $this->get_url_data( $this->api_config['url_user_id'], $userAccessToken, $userAccessTokenSecret );
        if( $user ){
            $userjson = json_decode( $user, true );
            if( isset( $userjson['userId'] ) ){
                $userId = $userjson['userId'];
                $values['userId'] = $userId;
                
                $prev = $this->sql->query_first_row( "SELECT * FROM users WHERE userId = '$userId'" );
                
                if( $prev ){
                    $cs_user_id = $prev['cs_user_id'];
                }else{
                    $this->sql->insert_or_update( 'users', array( 'userId' => $userId ) );
                    $cs_user_id = $this->sql->insert_id();
                }
            }else{
                $userId = NULL;
            }
            $values['cs_user_id'] = $cs_user_id;
        }


        $this->sql->insert_or_update( 'tokens', $values, array( 'userAccessToken' ) );
        $token_id = $this->sql->insert_id();
        
        $query = sprintf( "SELECT userAccessToken,userId,token_id,cs_user_id FROM tokens WHERE userAccessToken = '%s'", $userAccessToken );

        $rv = $this->sql->query_first_row( $query );

        return $rv;
    }

    function user_info_for_token_id( $token_id ){
        $query = sprintf( "SELECT * FROM tokens WHERE token_id = %d", $token_id );

        $rv = $this->sql->query_first_row( $query );

        return $rv;
    }

    function user_info( $userAccessToken ){
        $query = sprintf( "SELECT * FROM tokens WHERE userAccessToken = '%s'", $userAccessToken );

        $rv = $this->sql->query_first_row( $query );

        return $rv;
    }

    // if unique_keys null will use required, but sometimes
    // you wnat to exclude some keys from required to determine uniqueness
    // of the rows, for example skip callbackURL
    function process($table, $required, $unique_keys = NULL ) {
        $this->ensure_schema();
        
        $this->status->clear($table);

        $end_points_fields = array(
            'userId' => 'VARCHAR(1024)',
            'userAccessToken' => 'VARCHAR(512)',
        );

        $rawdata = file_get_contents("php://input");
        if( ! $rawdata ){
            $this->status->error('Input from query appears empty' );
        }

        if( $this->status->success() ) {
            $data = json_decode($rawdata,true);
            if( ! $data ) {
                $this->status->error( 'Failed to decode json' );
            }
            if( $this->status->success() ){
                $command_ids = array();
                foreach( $data as $summary_type => $activities){
                    foreach( $activities as $activity){
                        $row = array();
                    
                        foreach( $end_points_fields as $key => $value ){
                            if( array_key_exists( $key, $activity ) ){
                                $row[ $key ] = $activity[$key];
                            }else{
                                $this->status->error( sprintf( 'Missing end point field %s', $key ) );
                            }
                        }

                        foreach( $required as $key ){
                            if( array_key_exists( $key, $activity ) ) {
                                $row[$key] = $activity[$key];
                            }else{
                                $this->status->error( sprintf( 'Missing required field %s', $key ) );
                            }
                        }
                    
                        $extra = array();
                        foreach( $activity as $key => $value ){
                            if( ! array_key_exists( $key, $required ) && ! array_key_exists( $key, $end_points_fields ) ){
                                $extra[$key] = $value;
                            }
                        }
                        
                        if( count( $extra ) > 0 ){
                            $row['json'] = json_encode( $extra ) ;
                        }

                        $callbackURL = false;
                        if( isset( $row['callbackURL'] ) ){
                            $callbackURL = true;
                        }
                        
                        if( $this->status->success() ){
                            if( ! $this->sql->insert_or_update($table, $row, ($unique_keys != NULL ? $unique_keys : $required) ) ) {
                                $this->status->error( sprintf( 'SQL error %s', $this->sql->lasterror ) );
                            }
                        }
                        if( $this->status->success() ){
                            if( $callbackURL ) {
                                $found = $this->sql->query_first_row( sprintf( 'SELECT file_id FROM `%s` WHERE summaryId = %s', $table, $row['summaryId'] ) );
                                if( $found ){
                                    array_push( $command_ids, $found['file_id'] );
                                }
                            }
                        }
                        if( $this->status->success() ){
                            $this->maintenance_after_process($table, $row);
                        }
                    }
                    if( $command_ids ){
                        $this->exec_callback_cmd( $table, $command_ids );
                    }
                }
            }
        }
        $rv = $this->status->success();
        #$this->status->error('for debug logging');

        if( $this->status->hasError() ) {
            $this->status->record($this->sql,$rawdata);
        }
        return $rv;
    }

    function exec_backfill_cmd( $token_id, $days ){
        $log = sprintf( 'tmp/backfill_%d_%s', $token_id, strftime( '%Y%m%d_%H%M%S',time() ) );
        $command = sprintf( 'php runbackfill.php %s %s > %s.log 2> %s-err.log &', $token_id, $days, $log, $log );
        if( $this->verbose ){
            printf( 'Exec %s'.PHP_EOL, $command );
        }
        exec( $command );
    }
    
    function exec_callback_cmd( $table, $command_ids ){
        if( count($command_ids) > 25 ){
            $chunks = array_chunk( $command_ids, (int)ceil(count($command_ids)/5 ) );
        }else{
            $chunks = array( $command_ids );
        }
        foreach( $chunks as $chunk ){
            $file_ids = implode( ' ', $chunk );

            $command_base = sprintf( 'php runcallback.php %s %s', $table, $file_ids );
            $logfile = str_replace( ' ', '_', sprintf( 'tmp/callback-%s-%s-%s', $table, substr($file_ids,0,10),substr( hash('sha1', $command_base ), 0, 8 ) ) );

            $command = sprintf( '%s > %s.log 2> %s-err.log &', $command_base, $logfile, $logfile );
            if( $this->verbose ){
                printf( 'Exec %s'.PHP_EOL, $command );
            }
            exec( $command );
        }
    }
    
    function Authorization_header_for_token_id( $full_url, $token_id ){

        $row = $this->sql->query_first_row( "SELECT * FROM tokens WHERE token_id = $token_id" );
        return $this->Authorization_header( $full_url, $row['userAccessToken'], $row['userAccessTokenSecret'] );
        
    }

    function interpret_authorization_header( $header ){
        $maps = array();
        $split = explode( ', ', str_replace( 'OAuth ', '', $header ) );

        foreach( $split as $def ) {
            $sub = explode('=', str_replace( '"', '', $def ) );
            $maps[ $sub[0] ] = $sub[1];
        }
        return $maps;
    }
    
    function authenticate_header($token_id){
        $full_url = sprintf( '%s://%s%s', $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
        $header = apache_request_headers()['Authorization'];

        $maps = $this->interpret_authorization_header( $header );
        $userAccessToken = $maps['oauth_token'];
        $row = $this->sql->query_first_row( "SELECT userAccessTokenSecret,token_id FROM tokens WHERE userAccessToken = '$userAccessToken'" );
        $reconstructed = $this->Authorization_header( $full_url, $userAccessToken, $row['userAccessTokenSecret'], $maps['oauth_nonce'], $maps['oauth_timestamp'] );
        $reconstructed = str_replace( 'Authorization: ', '', $reconstructed );
        $reconmaps = $this->interpret_authorization_header( $reconstructed );
        // Check if token id is consistent with the token id of the access token
        if( $reconmaps['oauth_signature'] != $maps['oauth_signature'] || $row['token_id'] != $token_id ){
            header('HTTP/1.1 401 Unauthorized error');
            die;
        }
    }
    
    function authorization_header( $full_url, $userAccessToken, $userAccessTokenSecret, $nonce = NULL, $timestamp = NULL){
        $consumerKey = $this->api_config['consumerKey'];;
        $consumerSecret = $this->api_config['consumerSecret'];
    
        $url_info = parse_url( $full_url );

        $get_params = array();
        parse_str( $url_info['query'], $get_params );
        $url = sprintf( '%s://%s%s', $url_info['scheme'], $url_info['host'], $url_info['path'] );

        if( $nonce == NULL ){
            $nonce = bin2hex(random_bytes( 16 ));
        }
        if( $timestamp == NULL ){
            $timestamp = (string)round(microtime(true) );
        }

        $method = 'GET';

        $signatureMethod = 'HMAC-SHA1';
        $version = '1.0';

        $oauth_params = array(
            'oauth_consumer_key' => $consumerKey,
            'oauth_token' => $userAccessToken,
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => $timestamp,
            'oauth_version' => $version
        );

                     
        $all_params = array_merge( $oauth_params, $get_params );
        $params_order = array_keys( $all_params );
        sort($params_order);

        $base_params = array();

        foreach($params_order as $param) {
            array_push( $base_params, sprintf( '%s=%s', $param, $all_params[$param]) );
        }

        $base = sprintf( '%s&%s&%s', $method, rawurlencode($url), rawurlencode(implode('&',$base_params) ) );

        $key = rawurlencode($consumerSecret) . '&' . rawurlencode($userAccessTokenSecret);
        $oauth_params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        $header_params = array_keys($oauth_params);
        sort($header_params);
        $headers = array();
        foreach( $header_params as $param) {
            array_push( $headers, sprintf( '%s="%s"', $param, rawurlencode($oauth_params[$param] ) ) );
        }

        $header = sprintf( 'Authorization: OAuth %s', implode(', ', $headers) );
    
        return $header;
    }

    function get_url_data($url, $userAccessToken, $userAccessTokenSecret){
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
        $headers = [ $this->authorization_header( $url, $userAccessToken, $userAccessTokenSecret ) ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers );

        if( $this->verbose ){
            printf( "CURL: %s".PHP_EOL, $url );
        }
        $data = curl_exec($ch);
        if( $data === false ) {
            $this->status->error( sprintf( 'CURL Failed %s', curl_error($ch ) ) );
        }
        curl_close($ch);
        return $data;
    }

    function validate_user( $token_id ){
        $user = $this->user_info_for_id($token_id);
        unset( $user['userAccessTokenSecret'] );
        return $user;
    }
    
    function validate_input_id( $val ){
        return intval($val);
    }
    function validate_token( $val ){
        return filter_var( $val, FILTER_VALIDATE_REGEXP, array('options' => array( 'regexp' => '/^([-A-Za-z0-9]+)$/' ) ) );
    }

    function validate_url( $val ){
        return filter_var( $val, FILTER_VALIDATE_URL );
    }
    
    function validate_fit_file( $data ){
        if( strlen($data) < 13 ){
            return false;
        }
        $rv = unpack( 'c3', $data, 9 );

        return ( $rv[1] == 70 && $rv[2] == 73 && $rv[3] == 84 );
    }

    
    function run_file_callback( $table, $cbids ){
        $this->ensure_schema();
        foreach( $cbids as $cbid ){
            $this->file_callback_one( $table, $cbid );
        }
    }
    
    function file_callback_one( $table, $cbid ){
        
        $save_to_file = false;
        
        $this->status->clear('assets');
        
        $query = sprintf( "SELECT * FROM %s WHERE file_id = %s", $table, $cbid );
        $row = $this->sql->query_first_row( $query );
        if( ! $row ){
            $this->status->error( sprintf( 'sql error %s', $this->sql->lasterror ) );
        }
        
        if( $this->status->success() ){
            $callback_url = $row[ 'callbackURL' ];
            $callback_info = parse_url( $callback_url );
            $get_params = array();
            parse_str( $callback_info['query'], $get_params );

            $userAccessToken = $row[ 'userAccessToken' ];

            $user = $this->user_info( $userAccessToken );
            if( ! $user ){
                $this->status->error( "unregistered user for $userAccessToken" );
            }

            if( isset( $row['summaryId'] ) ){
                $fnamebase = sprintf( '%s.fit', $row['summaryId'] );
            }else{
                $fnamebase = sprintf( '%s.fit', hash('sha1',sprintf( '%s%s', $userAccessToken, $callback_url ) ) );
            }
            if( isset( $row['userId'] ) ){
                $pathdir = sprintf( "assets/%s/", substr( $row['userId'], 0, 8 ) );
            }else{
                $pathdir = sprintf( "assets/%s/", substr( $row['userAccessToken'], 0, 8 ) );
            }
            $path = sprintf( '%s/%s', $pathdir, $fnamebase );

            if( isset($user['userAccessTokenSecret'] ) ){
                $userAccessTokenSecret = $user['userAccessTokenSecret'];

                if( isset($row['userId']) && !isset($user['userId'])){
                    $this->sql->insert_or_update('tokens', array('userId'=>$row['userId'], 'token_id'=>$user['token_id']), array( 'token_id' ) );
                }
            
                $url = $callback_url;
                $ntries = 3;
                $nextwait = 60;
                $data = false;
                while( $ntries > 0 ){
                    $data = $this->get_url_data( $url, $userAccessToken, $userAccessTokenSecret );
                    if( $data && $this->validate_fit_file($data) ){
                        $ntries = 0;
                    }else{
                        if( $this->verbose ){
                            print( "Error: Failed to get callback data for $cbid, sleeping $nextwait".PHP_EOL );
                        }
                        $ntries-=1;
                        if( $ntries > 0 ){
                            $this->sleep( $nextwait );
                            $nextwait *= 2;
                        }
                    }
                }

                if($data === false ){
                    $this->status->error( 'Failed to get data' );

                    $row = array(
                        'tablename' => $table,
                        'file_id' => $cbid,
                        'message' => 'No Data for callback url',
                        'callbackURL' => $callback_url,
                        'userAccessToken' => $userAccessToken
                    );
                    $this->status->record($this->sql, $row );
                    if( $this->verbose ){
                        print( "Error: Failed repeatedly to get callback data for $cbid, skipping".PHP_EOL );
                    }
                }else{
                    if( $save_to_file ){
                        if( ! file_exists( $pathdir ) ){
                            mkdir( $pathdir, 0777, true );
                        }
                        file_put_contents($fname, $data);

                        $row = array(
                            'tablename' => $table,
                            'file_id' => $cbid,
                            'path' => $fname,
                            'filename' => $fnamebase,
                        );
                        $this->sql->insert_or_update( 'assets', $row, array( 'file_id', 'tablename' ) );
                        $assetid = $this->sql->insert_id();

                        $this->sql->insert_or_update( $table, array( 'file_id' => $cbid, 'asset_id' => $assetid ), array( 'file_id' ) );
                    }else{
                        $exists = $this->sql->query_first_row(sprintf("SELECT asset_id FROM assets WHERE file_id=%s AND tablename='%s'",$cbid,$table));

                        if( $exists ){
                            $query = "UPDATE assets SET data=? WHERE file_id=? AND tablename=?";
                            $stmt = $this->sql->connection->prepare( $query );
                        }else{
                            $query = "INSERT INTO assets (data,file_id,tablename) VALUES(?,?,?)";
                            $stmt = $this->sql->connection->prepare( $query );
                        }
                        if( $stmt ){
                            if( $this->verbose ){
                                printf( 'EXECUTE: %s'.PHP_EOL, $query );
                            }
                            $null = NULL;
                            $stmt->bind_param('bis',$null,$cbid,$table );
                            $stmt->send_long_data(0,$data);
                            if (!$stmt->execute()) {
                                $this->status->error(  "Execute failed: (" . $stmt->errno . ") " . $stmt->error );
                                if( $this->verbose ){
                                    printf( 'ERROR: %s [%s] '.PHP_EOL,  $stmt->error, $query);
                                }
                            }
                            $stmt->close();
                        }else{
                            $this->status->error( sprintf( 'Failed to prepare %s, %s', $query, $this->sql->lasterror ) );
                        }
                    }

                    if( $this->status->success() ){
                        $exists = $this->sql->query_first_row(sprintf("SELECT asset_id FROM assets WHERE file_id=%s AND tablename='%s'",$cbid,$table));
                        if( isset( $exists['asset_id'] ) ){
                            $this->sql->insert_or_update( $table, array( 'asset_id' => $exists['asset_id'], 'file_id'=> $cbid ), array( 'file_id' ) );
                        }else{
                            $this->status->error( 'Failed to get back asset_id' );
                        }
                    }
                }
            }
        }
        if( $this->status->hasError() ){
            $this->status->record( $this->sql, array( 'cbid' => $cbid, 'table'=>$table ) );
        }
    }

    function backfill_process($token_id, $start_year, $force = false){
        $year = max(intval($start_year),2000);
        $date = mktime(0,0,0,1,1,$year);
        $status = $process->backfill_should_start( $token_id, $date, $force );
        if( $status['start'] == true ){
            $process->backfill( $token_id, $date );
        }
        return( $status );
        
    }
    
    function backfill_should_start( $token_id, $start_date, $force = false ){
        $rv = array( 'start' => true, 'status' => 'No backfill yet' );
        
        $row = $this->sql->query_first_row( sprintf( 'SELECT UNIX_TIMESTAMP(MAX(ts)),MIN(summaryStartTimeInSeconds),MAX(summaryEndTimeInSeconds),MAX(backfillEndTime) FROM backfills WHERE token_id = %d', $token_id ) );

        # something already
        if( $row && isset( $row['UNIX_TIMESTAMP(MAX(ts))'] ) && $row['UNIX_TIMESTAMP(MAX(ts))'] ){
            // We had one already, no need to start new one
            $rv['start'] = false;
            
            $oldthreshold = time() - (15 * 60 );
            if( $row['UNIX_TIMESTAMP(MAX(ts))'] > $oldthreshold && $row['MAX(summaryEndTimeInSeconds)'] < $row['MAX(backfillEndTime)']){

                $eta = ($row['MAX(backfillEndTime)'] - $row['MAX(summaryEndTimeInSeconds)'])/(90*3600*24)*100;


                $eta_min = intval($eta / 60 );
                $eta_sec = $eta - ( $eta_min * 60.0 );
                
                $rv['status'] = sprintf( 'Backfill running (completed %s to %s, eta = %d:%d)',
                                         strftime( '%Y-%m-%d', $row['MIN(summaryStartTimeInSeconds)'] ),
                                         strftime( '%Y-%m-%d', $row['MAX(summaryEndTimeInSeconds)'] ),
                                         $eta_min, $eta_sec );
            }else{
                $rv['status'] = sprintf( 'Backfill from %s to %s completed on %s',
                                         strftime( '%Y-%m-%d', $row['MIN(summaryStartTimeInSeconds)'] ),
                                         strftime( '%Y-%m-%d', $row['MAX(summaryEndTimeInSeconds)'] ),
                                         strftime( '%Y-%m-%d', $row['MAX(backfillEndTime)'] ) );
            }
            // Unless it's an incomplete one for more than 15min, then restart (it should throttle 2min max)
            if( $row['MAX(summaryEndTimeInSeconds)'] < $row['MAX(backfillEndTime)'] && $row['UNIX_TIMESTAMP(MAX(ts))'] < $oldthreshold){
                $rv['start'] = true;

                $rv['status'] = sprintf( 'Previous backfill seem stalled at %s (now %s)', strftime("%Y-%m-%d, %H:%M:%S", $row['UNIX_TIMESTAMP(MAX(ts))']), strftime("%Y-%m-%d, %H:%M:%S", time() ) );
            }

            // or if asked year is before current start
            if( $row['MIN(summaryStartTimeInSeconds)'] > $start_date ){
                $rv['start'] = true;
                $rv['status'] = sprintf( 'Previous backfill did not go back far enough %s < %s',
                                         strftime("%Y-%m-%d", $start_date ),
                                         strftime("%Y-%m-%d", $row['MIN(summaryStartTimeInSeconds)'] ) );
            }
        }
        if( $force ){
            $rv['start'] = true;
        }
        return $rv;
    }

    function backfill( $token_id, $date ){
        $this->ensure_schema();

        $this->status->clear('backfills');

        $user = $this->sql->query_first_row( "SELECT userAccessToken,userAccessTokenSecret FROM tokens WHERE token_id = $token_id" );

        if( isset( $user['userAccessToken'] ) && isset( $user['userAccessTokenSecret'] ) ){
            // start and get rid of old one if exists
            $this->sql->execute_query( "DELETE FROM backfills WHERE token_id = $token_id" );
            
            $row = array( 'token_id' => $token_id,

                          'summaryStartTimeInSeconds' => $date,
                          'summaryEndTimeInSeconds' => $date,
                          'backfillEndTime' => time()
            );
            $this->sql->insert_or_update( 'backfills', $row );
            $this->exec_backfill_cmd($token_id, 90 );
        }
    }
    
    function run_backfill( $token_id, $days, $sleep = 100 ){
        # Start from 2010, record request time
        #  move 90 days forward from 2010 until reach request time
        # return true is more to do, false if reach end
        
        $this->ensure_schema();

        $save_to_file = false;
        
        $this->status->clear('backfills');

        $user = $this->sql->query_first_row( "SELECT * FROM tokens WHERE token_id = $token_id" );

        $moreToDo = false;
        
        if( isset( $user['userAccessToken'] ) && isset( $user['userAccessTokenSecret'] ) ){
            $sofar = $this->sql->query_first_row( "SELECT token_id,MIN(summaryStartTimeInSeconds),MAX(summaryEndTimeInSeconds),MAX(backfillEndTime) FROM backfills WHERE token_id = '$token_id' GROUP BY token_id" );
        
            if(isset($sofar['MAX(summaryEndTimeInSeconds)'] ) ){
                $start = $sofar['MAX(summaryEndTimeInSeconds)'];
                $backfillend = $sofar['MAX(backfillEndTime)'];

                $end = $start + (24*60*60*$days);
                $moreToDo = ($end < $backfillend);
                $next = array( 'token_id' => $token_id,
                               'summaryStartTimeInSeconds' => $start,
                               'summaryEndTimeInSeconds' => $end,
                               'backfillEndTime'=>$backfillend );

                $url = sprintf( $this->api_config['url_backfill_activities'], $next['summaryStartTimeInSeconds'], $next['summaryEndTimeInSeconds'] );
                $data = $this->get_url_data($url, $user['userAccessToken'], $user['userAccessTokenSecret']);
                if( true || $this->status->success() ){
                    $this->sql->insert_or_update( 'backfills', $next );
                }
            }
            if( $moreToDo ){
                if( $this->verbose ){
                    printf( 'Requested %s days, Sleeping %s secs', $days, $sleep );
                }
                $this->sleep($sleep);
                $this->exec_backfill_cmd($token_id, $days );
            }else{
                $row = array( 'backfillEndTime' => $backfillend, 'token_id' => $token_id );
                $this->sql->insert_or_update( 'tokens', $row, array( 'token_id' ));
                $row = array( 'backfillEndTime' => $backfillend, 'cs_user_id' => $user['cs_user_id'] );
                $this->sql->insert_or_update( 'users', $row, array( 'cs_user_id' ));
            }
        }

        return $moreToDo;
    }

    function sleep( $seconds ){
        sleep( $seconds );
        // After sleep, sql connect likely to time out
        $this->sql = new garmin_sql();
        $this->sql->verbose = $this->verbose;
    }
    
    function maintenance_after_process($table,$row){
        $to_set = array();
        $other_to_set = array();

        //
        // First see if we can update users
        //
        $cs_user_id = NULL;
        if( isset( $row['summaryId'] )){
            $fullrow = $this->sql->query_first_row( sprintf( "SELECT * FROM `%s` WHERE summaryId = '%s'", $table, $row['summaryId'] ) );

            if( !$fullrow['cs_user_id'] && isset( $row['userAccessToken'] ) && isset( $row['userId'] ) ){
            
                $userInfo = $this->user_info($row['userAccessToken']);
                $token_id = $userInfo['token_id'];
            
                if( ! isset( $userInfo['cs_user_id'] ) ){
                    // Check if use exists
                    $userId = $row['userId'];
                    $prev = $this->sql->query_first_row( "SELECT userId FROM users WHERE userId = '$userId'" );
                    if( ! $prev ){
                        $this->sql->insert_or_update( 'users', array( 'userId' => $userId ) );
                        $cs_user_id = $this->sql->insert_id();
                        $this->sql->execute_query( "UPDATE tokens SET cs_user_id = $cs_user_id, userId = '$userId' WHERE token_id = $token_id" );
                    }
                }else{
                    $cs_user_id = $userInfo['cs_user_id'];
                }

                array_push( $to_set, sprintf( 'cs_user_id = %d', $cs_user_id ) );
            }

            //
            //
            // Link activities / fitfiles on userId, startTimeInSeconds
            if( $table == 'activities' ){
                $other_table = 'fitfiles';
                $other_key = 'file_id';
                $table_key = 'activity_id';
            }else if( $table == 'fitfiles' ) {
                $other_table = 'activities';
                $other_key = 'activity_id';
                $table_key = 'file_id';
            }else{
                $other_table = NULL;
            }
            
            if( $other_table && (isset( $row['userId'] ) && isset( $row['startTimeInSeconds' ] ) ) ){
                $query = sprintf( "SELECT * FROM `%s` WHERE userId = '%s' AND startTimeInSeconds = %d", $other_table, $row['userId'], $row['startTimeInSeconds'] );
                $found = $this->sql->query_as_array( $query );

                if( count( $found ) == 1 ){
                    $found = $found[0];
                    if( ! $found[$table_key] ){
                        if( $fullrow[$table_key] ){
                            $query = sprintf( 'UPDATE `%s` SET %s = %d WHERE %s = %d', $other_table, $table_key, $fullrow[$table_key], $other_key, $found[$other_key] );
                            $this->sql->execute_query( $query );
                        }
                    }
                    if( ! $fullrow[$other_key] ){
                        array_push( $to_set, sprintf( '%s = %d', $other_key, $found[$other_key] ) );
                    }
                }
            }
        
            if( count( $to_set ) ){
                $query = sprintf( "UPDATE `%s` SET %s WHERE summaryId = '%s'".PHP_EOL,  $table, implode( ', ', $to_set), $row['summaryId'] );
                if( ! $this->sql->execute_query( $query ) ){
                    printf( "ERROR %s",  $this->sql->lasterror );
                }
            }
        }
    }

    // This will update cs_user_id in table by matching userId from the service data
    function maintenance_link_cs_user($table,$limit=20){
        $this->ensure_schema();
        
        $res = $this->sql->query_as_array( "SELECT userId,COUNT(userId) FROM $table WHERE ISNULL(cs_user_id) GROUP BY userId LIMIT $limit" );

        foreach( $res as $one ){
            $userId = $one['userId'];

            #$prev = $this->sql->query_first_row( "SELECT * FROM users WHERE userId = '$userId'" );
            $prev = $this->sql->query_first_row( "SELECT * FROM users" );
                    
            if( isset( $prev['cs_user_id'] ) ){
                $cs_user_id = $prev['cs_user_id'];
            }else{
                $this->sql->execute_query( sprintf( "INSERT INTO users (userId) VALUES ('%s')", $userId ) );
                $cs_user_id = $this->sql->insert_id() ;
            }
            $this->sql->execute_query( sprintf( "UPDATE %s SET cs_user_id=%s WHERE userId='%s'", $table, $prev['cs_user_id'], $userId ) );
        }
    }

    // this will rerun the callback functions from fitfiles (push) that didn't succeed and are still missing
    function maintenance_fix_missing_callback($cs_user_id = NULL){
        if( $cs_user_id ){
            $query = sprintf( 'SELECT * FROM fitfiles WHERE ISNULL(asset_id) AND cs_user_id = %d', intval($cs_user_id) );
        }else{
            $query = 'SELECT * FROM fitfiles WHERE ISNULL(asset_id)';
        }
        $missings = $this->sql->query_as_array( $query );
        $command_ids = array();
        foreach( $missings as $one ){
            array_push( $command_ids, $one['file_id'] );
        }
        if( count( $command_ids ) ){
            $this->exec_callback_cmd('fitfiles', $command_ids );
        }else{
            if( $this->verbose ){
                printf( 'No missing callback found'.PHP_EOL );
            }
        }
    }

    // this will try to match to fitfiles, activities that are not having any detail files
    function maintenance_link_activity_files($cs_user_id=NULL,$limit=20){
        $this->ensure_schema();

        if( $cs_user_id ){
            $query = sprintf( "SELECT * FROM activities WHERE ISNULL(file_id ) AND cs_user_id = %d LIMIT %d", $cs_user_id, $limit );
        }else{
            $query = "SELECT * FROM activities WHERE ISNULL(file_id ) LIMIT $limit";
        }
        $res = $this->sql->query_as_array( $query );

        $mintime = NULL;
        $maxtime = NULL;
        
        foreach( $res as $one ){
            $startTime = $one['startTimeInSeconds'];
            $cs_user_id = $one['cs_user_id'];
            $found = $this->sql->query_first_row( "SELECT * FROM fitfiles WHERE startTimeInSeconds=$startTime AND cs_user_id=$cs_user_id" );
            if( $found ){
                $query = sprintf( 'UPDATE activities SET file_id = %s WHERE activity_id = %s', $found['file_id'], $one['activity_id'] );
                $this->sql->execute_query( $query );
            }else{
                if( $this->verbose ){
                    printf( 'Missing activity for %s'.PHP_EOL, strftime("%Y-%m-%d, %H:%M:%S", $startTime ) );
                }
                if( $mintime == NULL ||  $startTime < $mintime ){
                    $mintime = $startTime;
                }
                if( $maxtime == NULL || $startTime > $maxtime ){
                    $maxtime = $startTime;
                }
            }
        }

        if( count( $res ) > 0){
            printf( 'Missing %d activities between %s and %s'.PHP_EOL, count( $res ), strftime("%Y-%m-%d", $mintime ), strftime("%Y-%m-%d", $maxtime ) );
            $endtime = $mintime + (24*60*60*90); // 90 is per max throttle from garmin
            $url = sprintf( $this->api_config['url_backfill_activities'], $mintime, $endtime );
            $user = $this->user_info_for_token_id( 1 );
            $data = $this->get_url_data($url, $user['userAccessToken'], $user['userAccessTokenSecret']);
        }
            
    }
    

    function query_file( $cs_user_id, $activity_id, $file_id ){
        if( $file_id ){
            $query = "SELECT data FROM assets WHERE file_id = $file_id";
        }else if( $activity_id ){
            $query = "SELECT data FROM activities act, assets ast WHERE act.file_id = ast.file_id AND act.activity_id = $activity_id";
        }

        $stmt = $this->sql->connection->query($query);
        if( $stmt ){
            $results = $stmt->fetch_array( MYSQLI_ASSOC );
            if( $results ){
                return $results['data'];
            }
        }
        return NULL;
    }

    function query_activities( $cs_user_id, $start, $limit ){

        $query = "SELECT activity_id,json FROM activities WHERE cs_user_id = $cs_user_id ORDER BY startTimeInSeconds DESC LIMIT $limit OFFSET $start";

        $res = $this->sql->query_as_array( $query );
        $json = array();
        foreach( $res as  $one ){
            if( isset($one['json']) ){
                $activity_json = json_decode($one['json'], true );
                $activity_json['cs_activity_id'] = $one['activity_id'];
                              
                array_push($json, $activity_json );
            }
        }
        $query = "SELECT COUNT(json) FROM activities WHERE cs_user_id = $cs_user_id";
        $count = $this->sql->query_first_row( $query );

        $rv = array( 'activityList' => $json, 'paging' => array( 'total' => intval( $count['COUNT(json)'] ), 'start' => intval($start), 'limit' => intval($limit) ));
        
        print( json_encode( $rv ) );
    }
};
    
?>
