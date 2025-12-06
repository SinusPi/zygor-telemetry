<?php
class VerboseException extends \Exception {
    private $_payload;

    public function __construct($message, 
                                $code = 0, 
                                Exception $previous = null, 
                                $payload = null) 
    {
        if (is_array($message)) {
            $payload=$message;
            $message=$payload['message'] ?: $payload[0];
            $code=$payload['code'] ?: $payload[1];
        }
        parent::__construct($message, $code, $previous);

        $this->_payload = $payload;
    }

    public function GetPayload() { return $this->_payload; }
}
