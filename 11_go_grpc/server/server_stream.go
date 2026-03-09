package main

import (
	"go_grpc_demo/proto"
	"log"
	"time"
)

func (s *helloServer) SayHelloServerStreaming(req *proto.NamesList, stream proto.GreetService_SayHelloServerStreamingServer) error {
	log.Printf("got request with names : %v", req.Names)
	for _, name := range req.Names {
		res := &proto.HelloResponse{
			Message: "Hello" + name,
		}
		if err := stream.Send(res); err != nil {
			return err
		}
		time.Sleep(2 * time.Second)
	}
	return nil
}
