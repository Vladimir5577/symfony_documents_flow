package main

import (
	"go_grpc_demo/proto"
	"log"
	"net"

	"google.golang.org/grpc"
)

const (
	port = ":8070"
)

type helloServer struct {
	proto.GreetServiceServer
}

func main() {
	lis, err := net.Listen("tcp", port)
	if err != nil {
		log.Fatalf("Failed to start the server %v", err)
	}
	grpcServer := grpc.NewServer()
	proto.RegisterGreetServiceServer(grpcServer, &helloServer{})
	log.Printf("server Started at %v", lis.Addr())
	if err := grpcServer.Serve(lis); err != nil {
		log.Fatalf("Failed to start: %v", err)
	}
}
