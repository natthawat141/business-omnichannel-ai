# Downloadable Management Agent package

`document-intake-agent-0.2.0.tgz` is the credential-free, locally built CLI/MCP
package referenced by `/docs/agent-setup`. Build it on the isolated GCP review
VM with `npm ci`, `npm test`, `npm run build`, and `npm pack`; inspect the pack
manifest before staging the archive here. It must never contain `.env` files,
keys, customer documents, test fixtures, or `node_modules`.

Current archive SHA-256:
`d768700d9367f81a13b9c3a92e43bb1f642eba41594c45b3e985935316e3df15`.

The archive is a deployment artifact, not proof that a hosted remote MCP
connector exists. The app still supports local stdio only.
