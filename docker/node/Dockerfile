# syntax=docker/dockerfile:1

FROM node:25.9.0-alpine AS development
WORKDIR /usr/src/app

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache shadow && \
    usermod -u ${UID} node && \
    groupmod -g ${GID} node

RUN chown -R node:node /usr/src/app

USER node

COPY --chown=node:node package*.json ./

RUN npm install

COPY --chown=node:node . .

CMD ["npm", "run", "dev"]
