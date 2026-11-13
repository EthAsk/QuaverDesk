<div class="card">
    <h2>What we send</h2>
    <p class="small">A <code>POST</code> of flat JSON. Every event has <code>id</code>, <code>event</code> and <code>created_at</code>, then its own fields, for example:</p>
    <pre class="small">{
  "id": "6f1c…", "event": "call.ended", "created_at": "2026-11-13T09:30:00+00:00",
  "call_id": 412, "direction": "inbound", "from": "+441142000000", "to": "+441142111111",
  "status": "completed", "duration_seconds": 184, "user_name": "Ann Agent", "user_extension": "201"
}</pre>
    <p class="small">Answer with any 2xx status within 10 seconds. If you don't, we try again after 1 minute, 5 minutes, 30 minutes, 2 hours, 6 hours and 24 hours, so the same event can arrive twice: use <code>id</code> to ignore repeats. Deliveries are kept for 7 days.</p>
    <p class="small"><strong>Check it's from us.</strong> Each request has an <code>X-QuaverDesk-Signature: t=…,v1=…</code> header. <code>v1</code> is the hex HMAC-SHA256 of <code>t + "." + the raw body</code> using your signing secret. Reject it if the secret doesn't match, or if <code>t</code> is more than 5 minutes old.</p>
    <p class="small muted">Webhooks go to <code>https://</code> addresses only. For Zapier, use a <em>Webhooks by Zapier → Catch Hook</em> trigger and paste its URL above; Zapier doesn't check signatures, so you can ignore that header there.</p>
</div>
