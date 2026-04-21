/**
 * NoovaERP — AI Accounting Demo Script
 * =====================================
 * Paste this entire script into the browser DevTools console while on:
 *   http://localhost:5173/ai/chat?module=accounting_insights
 *
 * The script will automatically send demo messages one by one,
 * waiting for each AI response before sending the next.
 * Perfect for screen recording.
 *
 * How to use:
 *   1. Log in and navigate to the AI Accounting chat
 *   2. Wait for the chat to auto-open (accounting_insights module)
 *   3. Open DevTools → Console
 *   4. Paste this script and press Enter
 *   5. Start your screen recorder
 *   6. Watch the demo run automatically
 */

const MESSAGES = [
  {
    label: "1 / 4  — Anomaly Detection",
    text: "Can you detect any anomalies or unusual entries in our journal entries for this year?",
    delayAfter: 2000,
  },
  {
    label: "2 / 4  — Collections Prioritization",
    text: "Which customers should I prioritize for collections right now?",
    delayAfter: 2000,
  },
  {
    label: "3 / 4  — AP Coding Suggestions",
    text: "Can you suggest account codes for our AP invoices based on past postings?",
    delayAfter: 2000,
  },
  {
    label: "4 / 4  — Revenue Review",
    text: "Please review our revenue invoices for any risks or anomalies.",
    delayAfter: 2000,
  },
];

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Set a React-controlled input value and fire the synthetic change event. */
function setReactInputValue(input, value) {
  const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
    window.HTMLInputElement.prototype,
    "value"
  ).set;
  nativeInputValueSetter.call(input, value);
  input.dispatchEvent(new Event("input", { bubbles: true }));
}

/** Wait for ms milliseconds. */
const wait = (ms) => new Promise((res) => setTimeout(res, ms));

/** Find the chat input (shadcn Input inside the chat form). */
function getChatInput() {
  return document.querySelector('input[placeholder="Type your message..."]');
}

/** Find the Send button (the icon button next to the input). */
function getSendButton() {
  const input = getChatInput();
  if (!input) return null;
  // The send button is the sibling button next to the input wrapper
  const wrapper = input.closest(".flex.gap-2");
  if (!wrapper) return null;
  return wrapper.querySelector("button:last-child");
}

/** Count current AI message bubbles to detect when a new response arrives. */
function countMessages() {
  // Messages are rendered in a scrollable div; count all message elements
  const msgs = document.querySelectorAll('[class*="message"], [class*="bubble"]');
  // Fallback: count paragraphs inside the chat scroll area
  const area = document.querySelector('[class*="overflow-y-auto"]');
  if (area) return area.querySelectorAll("p, div[class*='bg-']").length;
  return msgs.length;
}

/** Wait until the AI has finished responding (message count increases + loading stops). */
async function waitForResponse(previousCount, timeoutMs = 90000) {
  const start = Date.now();
  // First, wait for new content to appear
  while (Date.now() - start < timeoutMs) {
    await wait(800);
    const current = countMessages();
    if (current > previousCount) {
      // Wait a bit more to let the streaming finish
      await wait(3000);
      // Check that the loading spinner / send button is re-enabled
      const sendBtn = getSendButton();
      if (sendBtn && !sendBtn.disabled) return true;
      await wait(2000);
      return true;
    }
  }
  console.warn("⏱ Timed out waiting for AI response.");
  return false;
}

// ─── Main Demo Runner ─────────────────────────────────────────────────────────

async function runDemo() {
  console.log("🎬  NoovaERP — AI Accounting Demo starting…");
  console.log("    Make sure you are on /ai/chat?module=accounting_insights");
  console.log("─".repeat(60));

  await wait(3000); // Give the page time to settle after script injection

  for (let i = 0; i < MESSAGES.length; i++) {
    const { label, text, delayAfter } = MESSAGES[i];

    console.log(`\n📩  Sending: ${label}`);
    console.log(`    "${text}"`);

    const input = getChatInput();
    if (!input) {
      console.error("❌  Could not find chat input. Is the chat open?");
      return;
    }

    const prevCount = countMessages();

    // Type message into input
    setReactInputValue(input, text);
    await wait(600); // Small pause so it looks natural on screen

    // Press Enter to send
    input.dispatchEvent(
      new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true })
    );
    await wait(300);

    // Fallback: click the send button if Enter didn't work
    const sendBtn = getSendButton();
    if (sendBtn && sendBtn.disabled === false) {
      sendBtn.click();
    }

    console.log(`    ⏳  Waiting for AI response…`);
    await waitForResponse(prevCount);
    console.log(`    ✅  Response received.`);

    if (i < MESSAGES.length - 1) {
      console.log(`    💤  Pausing ${delayAfter / 1000}s before next message…`);
      await wait(delayAfter);
    }
  }

  console.log("\n" + "─".repeat(60));
  console.log("🏁  Demo complete! All 4 AI accounting capabilities demonstrated.");
  console.log("    Stop your screen recorder now.");
}

// Kick it off
runDemo().catch((err) => console.error("Demo error:", err));
