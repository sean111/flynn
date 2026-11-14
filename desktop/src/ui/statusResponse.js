/** Fences stale get_status completions across both response order and login changes. */
export class StatusResponseGuard {
    #sequence = 0;
    #generation = 0;

    invalidate() {
        this.#generation += 1;
    }

    begin() {
        return { sequence: ++this.#sequence, generation: this.#generation };
    }

    isCurrent(ticket) {
        return ticket.sequence === this.#sequence && ticket.generation === this.#generation;
    }
}
