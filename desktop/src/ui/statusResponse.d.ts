export type StatusRequestTicket = Readonly<{
    sequence: number;
    generation: number;
}>;

export class StatusResponseGuard {
    invalidate(): void;
    begin(): StatusRequestTicket;
    isCurrent(ticket: StatusRequestTicket): boolean;
}
