# AI Bot Chatwoot Architecture

## Scope

Version 1 serves one business. Laravel Management is the system of record for business
knowledge and catalog data. Chatwoot is the system of record for conversations, inboxes, and
human ownership. The Python service orchestrates AI replies and never connects directly to a
Management database.

## Runtime architecture

```mermaid
flowchart TD
    subgraph linePlatform["LINE Platform & Client"]
        customer(["👤 Customer Mobile App"])
        richmenu["🖼️ LINE Rich Menu\n(6 Actions: Condo, House, Consign, Loan, Hours, Staff)"]
        linemessaging["📡 LINE Messaging API\n(Webhook & Push API)"]
    end

    caddy["🔒 Caddy HTTPS Reverse Proxy\n(SSL Termination & Routing)"]

    subgraph chatwoot["Chatwoot Conversation Platform"]
        rails["Chatwoot Rails API\nConversation & Inbox Owner"]
        sidekiq["Chatwoot Sidekiq\nAsync Delivery Jobs"]
        cwdb[("PostgreSQL\nChatwoot DB")]
        cwredis[("Redis\nChatwoot Cache")]
        inbox["Configured Chatwoot Inbox\nAgent Bot Webhook"]
    end

    subgraph ai["AI Orchestration Boundary"]
        webhook["AI Webhook API\nFastAPI"]
        queue[("Redis Durable Queue\nEvents")]
        worker["AI Worker\nSingle-process Locking & Routing"]
        openrouter["🧠 OpenRouter\nLLM Completion"]
    end

    subgraph management["Laravel Management (System of Record)"]
        react["Admin Dashboard\nReact + Inertia UI"]
        laravel["Laravel API v1\nCatalog, Knowledge, Flex Generator"]
        mdb[("MySQL\nManagement DB")]
    end

    %% Inbound Flow
    customer -->|Tap Menu Button / Type Chat| richmenu
    richmenu -->|Message Event| linemessaging
    linemessaging -->|Webhook Event| caddy
    caddy -->|/webhooks/line| rails
    rails --> inbox
    rails <--> sidekiq
    rails <--> cwdb
    sidekiq <--> cwredis

    %% AI Pipeline
    inbox -->|Webhook POST /webhooks/chatwoot/{token}| webhook
    webhook -->|rpush event| queue
    queue -->|consume| worker

    %% Management API & Knowledge Integration
    worker -->|1. Search Catalog / FAQs / Business Profile| laravel
    worker -->|2. Fetch Structured LINE Flex JSON| laravel
    laravel <--> mdb
    react -->|Staff CRUD / Admin Profile| laravel

    %% LLM Grounding
    worker -->|3. Grounded Thai Chat Completion| openrouter

    %% Outbound Multi-Channel Delivery
    worker -->|4a. Deliver Conversational Text Message| rails
    rails -->|Send Message| linemessaging
    worker -->|4b. Direct LINE Push API: Flex Carousel & Cards| linemessaging
    linemessaging -->|Deliver Rich Cards & Chat| customer

    %% Staff Handoff & Return
    worker -->|Assign Team & Set Labels (human-handling)| rails
    rails -.->|Return to AI Label Event| webhook
```

## Message Lifecycle & Data Flow

1. **Customer Interaction via LINE:**
   * A customer taps a button on the **LINE Rich Menu** (e.g. 🏢 คอนโด, 🏡 บ้าน, 💰 สินเชื่อ, 📝 ฝากขาย, 🕒 ข้อมูล) or types a free-text message.
   * LINE delivers the message event through the **LINE Messaging API** to Caddy and into **Chatwoot's LINE Inbox**.

2. **Event Queueing & Orchestration:**
   * Chatwoot fires an `Agent Bot` webhook event to the **AI Webhook API (`FastAPI`)**.
   * The webhook validates the token and enqueues the payload into **Redis**.
   * The **AI Worker** consumes the event under a per-conversation asyncio lock to guarantee sequential processing.

3. **Data Retrieval from Laravel Management (System of Record):**
   * The AI worker queries the **Laravel Management API (`/api/v1`)**:
     * `POST /api/v1/catalog/search`: Retrieves real available condo/house listings with attribute filters.
     * `GET /api/v1/business-profile`: Retrieves authoritative business hours, contact info, and company metadata.
     * `GET /api/v1/faqs` & `GET /api/v1/knowledge`: Retrieves verified business policies and Q&As.
     * `POST /api/v1/flex/carousel` with `{ "item_ids": [...] }`: Builds the AI search carousel from
       exact ordered result IDs after rechecking eligibility. The category-wide `GET` carousel remains
       a compatibility/manual-discovery route and is not used to replace AI search results.
     * `GET /api/v1/flex/catalog/{id}` and `GET /api/v1/flex/{loan|consignment|about}`: Return structured **LINE Flex Message JSON**.

4. **Response Delivery (Hybrid Text + Flex Cards):**
   * **Structured UI (Flex Cards):** For catalog listings, the AI Worker sends the exact ordered Catalog
     Search result IDs to Management. Management rechecks active, published, effective, and available state,
     builds cards only for eligible IDs, and the worker pushes that **LINE Flex Carousel** through the
     **LINE Messaging Push API**. Official service cards use their dedicated Management endpoints.
   * **Conversational AI Text:** The AI Worker invokes **OpenRouter LLM** with grounded context to synthesize a natural, polite Thai chat response and sends it through the **Chatwoot Messages API**.

5. **Human Handoff & Return to AI:**
   * If the customer requests human assistance or mentions complaints/payment issues, the worker assigns the conversation to the staff team and applies the `human-handling` label.
   * When staff apply the `return-to-ai` label in Chatwoot, the worker resets `ai_mode` to `ai`, unassigns staff, and resumes automated AI responses.

## Ownership and security boundaries

| Concern | Owner | Boundary |
|---|---|---|
| Conversation history and inbox state | Chatwoot | Rails API and PostgreSQL (`enable_auto_assignment = false` on shared inboxes) |
| Business catalog and knowledge | Laravel Management | Authenticated read API and MySQL |
| Primary property image files | Cloudflare Images | One-time direct upload; Management stores the HTTPS delivery URL |
| AI orchestration and retries | Python AI service | FastAPI, Redis queue, worker |
| Model completion | OpenRouter | Outbound HTTPS from AI service |
| Human handoff | Chatwoot team | Team assignment; no individual agent binding until staff claims |

### Critical Inbox & Session Invariants:
1. **Inbox Auto-Assignment Disabled:** Inboxes MUST keep `enable_auto_assignment = false`. All incoming AI-managed conversations remain in `Open` status and `Unassigned` so that all human agents and administrators see them in real-time in the central queue (`Unassigned` / `All`).
2. **Clean Delivery on LINE:** When an interactive Flex Message is pushed to LINE, the worker records the AI response in Chatwoot as a **Private Note** (`private = true`) to prevent sending duplicate raw text bubbles to the customer's LINE chat while maintaining full audit logs for staff.
3. **Secrets Isolation:** Secrets are supplied through runtime environment files and are excluded from Git. Customer messages are not written to application logs.
4. **Exact Result/Card Parity:** Search responses and property cards use one ordered set of item IDs. A
   category-wide or latest-listing query must not replace the search result when replying to a customer.
5. **Consent Before Relaxation:** A zero-result property search asks the customer before removing location,
   price, or attribute constraints. Category and sale/rent intent remain applied to the relaxed query.
   If the relaxed query is also empty, the worker sends a deterministic no-result message and does not call
   the LLM to invent alternatives.
6. **Primary Image:** An authenticated admin may request a short-lived upload URL from Management and
   upload one JPEG, PNG, or WebP image directly to Cloudflare Images. The Cloudflare API token remains in
   the Management runtime; only the HTTPS delivery URL is stored as `primary_image_url` and becomes the
   property card hero. Cards without a primary image omit the hero block, and empty specifications use
   neutral copy. Automatic deletion of orphaned/replaced images is outside lean Version 1.

## Deployment topology

All services run in one Docker Compose stack on the development VM. Caddy is the only public
entry point. Chatwoot and Management keep their public hostnames, while the AI webhook has a
separate public HTTPS hostname because Chatwoot rejects private Compose hostnames for Agent Bot
webhooks. Caddy forwards that hostname privately to the AI API; the webhook still requires the
secret path token. Chatwoot, Management, AI API, and the AI Worker communicate over the private
Compose network. Persistent state is held in named volumes for Chatwoot PostgreSQL/Redis,
Management MySQL, and Chatwoot storage.
