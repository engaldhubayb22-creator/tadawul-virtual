# One image with the four Go programs: engine, feed, bot and seed.
# Build from the repository root:  docker build -f engine/Dockerfile .
FROM golang:1.24-alpine AS build
WORKDIR /src
COPY engine/go.mod engine/go.sum ./
RUN go mod download
COPY engine/ ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/ ./cmd/...

FROM alpine:3.20
# tzdata: the engine reads the market's time zone (Asia/Riyadh) by name.
RUN apk add --no-cache tzdata ca-certificates && adduser -D -u 10001 app
COPY --from=build /out/ /usr/local/bin/
USER app
CMD ["engine"]
