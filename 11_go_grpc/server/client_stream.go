package main

import (
	"go_grpc_demo/proto"
	"io"
	"log"
)

func (s *helloServer) SayHelloClientStream(stream proto.GreetService_SayHelloClientStreamServer) error {
	var messages []string
	for {
		req, err := stream.Recv()
		if err == io.EOF {
			return stream.SendAndClose(&proto.MessagesList{Messages: messages})
		}
		if err != nil {
			return err
		}
		log.Printf("Got request with name: %v", req.Name)
		messages = append(messages, "Hello ", req.Name)
	}
}
