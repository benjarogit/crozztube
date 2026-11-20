import { Plugin } from "@opencode/plugin"
import { evaluateHarnessCommand, shouldEvaluate } from "../../../tools/harness/opencode-adapter.mjs"

/*
 * Smallest project-local bridge to the existing CrozzTube harness hook.
 *
 * OpenCode V2 runs `permission.evaluate` after the configured rules and before
 * a shell action. Only the gate-critical harness commands (`deny-probe` and
 * `cli.mjs begin N`) are forwarded to `tools/harness/codex-hook.mjs` with its
 * `beforeShellExecution` contract. A denial there becomes `effect: "deny"`.
 * An allow is never written back, so configured permissions stay authoritative.
 */
export default Plugin.define({
  id: "crozztube.harness",
  async setup(ctx) {
    const root = ctx.location?.project?.canonical || ctx.location?.directory
    if (!root) return

    try {
      const registration = await ctx.permission.hook("evaluate", (event) => {
        if (event.action !== "shell" || event.effect === "deny") return
        const command = Array.isArray(event.resources) ? event.resources.join("\n") : ""
        if (!shouldEvaluate(command)) return
        const decision = evaluateHarnessCommand({ root, command, sessionID: event.sessionID })
        if (!decision.deny) return
        event.effect = "deny"
        event.message = decision.message
      })
      return () => registration?.dispose?.()
    } catch (error) {
      // A failed registration must never break the editor. Without a real
      // receipt the harness completion gate stays closed on its own.
      console.error("crozztube.harness: adapter registration failed", error?.message || error)
    }
  },
})
