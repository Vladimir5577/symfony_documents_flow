<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Grpc\TestService;

/**
 */
class GreetServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Grpc\TestService\NoParam $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SayHello(\Grpc\TestService\NoParam $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/TestService.GreetService/SayHello',
        $argument,
        ['\Grpc\TestService\HelloResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Grpc\TestService\NamesList $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function SayHelloServerStreaming(\Grpc\TestService\NamesList $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/TestService.GreetService/SayHelloServerStreaming',
        $argument,
        ['\Grpc\TestService\HelloResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ClientStreamingCall
     */
    public function SayHelloClientStream($metadata = [], $options = []) {
        return $this->_clientStreamRequest('/TestService.GreetService/SayHelloClientStream',
        ['\Grpc\TestService\MessagesList','decode'],
        $metadata, $options);
    }

    /**
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\BidiStreamingCall
     */
    public function SayHelloBidirectionalStreaming($metadata = [], $options = []) {
        return $this->_bidiRequest('/TestService.GreetService/SayHelloBidirectionalStreaming',
        ['\Grpc\TestService\HelloResponse','decode'],
        $metadata, $options);
    }

}
