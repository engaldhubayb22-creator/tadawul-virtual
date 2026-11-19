# Builds the Vue app and serves it with nginx, which also routes /api to
# PHP-FPM and /ws to the live feed. Build from the repository root:
#   docker build -f web/Dockerfile .
FROM node:22-alpine AS build
WORKDIR /app
COPY web/package.json web/package-lock.json ./
RUN npm ci
COPY web/ ./
RUN npm run build

FROM nginx:1.27-alpine
COPY deploy/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist/ /usr/share/nginx/html/
