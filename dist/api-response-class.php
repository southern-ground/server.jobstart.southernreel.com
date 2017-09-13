<?php
/**
 * Created by PhpStorm.
 * User: fst
 * Date: 8/1/17
 * Time: 3:31 PM
 */

class Response
{

    public $error = 0;
    public $message = "";
    public $type = "";

    private $env;
    private $link;
    private $params = array();

    function __construct()
    {
        $this->env = $_SERVER["HTTP_HOST"] === STAGE_SERVER ? ENV_STAGE : ENV_PROD;
        $this->type = $_SERVER["REQUEST_METHOD"];
    }

    function db_connect()
    {
        if ($this->env === ENV_STAGE) {
            $this->link = new mysqli("localhost", STAGE_DB_USER, STAGE_DB_PASSWORD, STAGE_DB_NAME);
            // $this->link = mysqli_connect("localhost", STAGE_DB_USER, STAGE_DB_PASSWORD, STAGE_DB_NAME);
            if (!$this->link) {
                $this->sql_connect_error();
                $this->autofellate();
            }
            return $this->link;
        } else if ($this->env === ENV_PROD) {
            $this->link = new mysqli("localhost", PROD_DB_USER, PROD_DB_PASSWORD, PROD_DB_NAME);
            // $this->link = mysqli_connect("localhost", PROD_DB_USER, PROD_DB_PASSWORD, PROD_DB_NAME);
            if (!$this->link) {
                $this->sql_connect_error();
                $this->autofellate();
            }
            return $this->link;
        }

        $this->error = 405;
        $this->message = "Invalid environment";

        return null;
    }

    function sql_connect_error()
    {
// Error connecting:
        $this->error = 501;
        $this->message = "Unable to connect to MySQL. Error #" . mysqli_connect_errno() . " Msg: " . mysqli_connect_error();
        $this->autofellate();
    }

    function bad_query($msg = "Improperly formed query.")
    {
// Error connecting:
        $this->error = 501;
        $this->message = $msg;
        $this->autofellate();
    }

    function bad_method($msg = "Invalid method")
    {
        $this->error = 405;
        $this->message = $msg;
        $this->autofellate();
    }

    function GUID()
    {
        if (function_exists('com_create_guid') === true) {
            return trim(com_create_guid(), '{}');
        }

        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    function autofellate()
    {
        header('Content-Type: application/json');
        die(json_encode($this));
    }

}