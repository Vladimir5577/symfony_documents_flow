Source
    https://github.com/AkhilSharma90/GO-grpc-demo

Init project:
    $ go mod init go_grpc_demo

// ==========================================
Install golang compiler 
    $ go install google.golang.org/protobuf/cmd/protoc-gen-go@latest
    $ go install google.golang.org/grpc/cmd/protoc-gen-go-grpc@latest

    update path
    $ export PATH="$PATH:$(go env GOPATH)/bin"

// ===========================================

Generate files from proto
    1. Create name.proto file
    then generate
    $ protoc --go_out=. --go-grpc_out=. proto/greet.proto

// ============================================
Install missing packages
    $ go mod tidy

// ============================================
Run project.
Open in terminal server folder and run 
    $ go run *.go

Open in another terminal client folder and run
    $ go run *.go

In client folder in main.go file coment and uncomment functions whish need to run