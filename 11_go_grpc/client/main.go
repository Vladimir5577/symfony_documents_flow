package main

import (
	"go_grpc_demo/proto"
	"log"

	"google.golang.org/grpc"
	"google.golang.org/grpc/credentials/insecure"
)

const (
	port = ":8070"
)

func main() {
	conn, err := grpc.Dial("localhost"+port, grpc.WithTransportCredentials(insecure.NewCredentials()))
	if err != nil {
		log.Fatalf("Not connect: %v", err)
	}
	defer conn.Close()

	client := proto.NewGreetServiceClient(conn)

	// names := &proto.NamesList{
	// 	Names: []string{"Bob", "Jack", "Mike"},
	// }

	callSayHello(client)
	// callSayHelloServerStream(client, names)
	// callSayHelloClientStream(client, names)
	// callHelloBidirectionalStream(client, names)
}
