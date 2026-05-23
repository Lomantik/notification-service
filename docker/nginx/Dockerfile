# syntax=docker/dockerfile:1

FROM nginx:1.30-alpine AS development
WORKDIR /usr/share/nginx/html

COPY ./docker/nginx/conf.d /etc/nginx/conf.d
COPY ./docker/nginx/nginx.conf /etc/nginx/nginx.conf
