/**
 * A business's name and the booking settings its pages show (08).
 */
export type BusinessSettings = {
    name: string;
    timezone: string;
    strictness: number;
    relaxationHours: number;
    minNoticeMinutes: number;
};

/**
 * One of a business's active services.
 */
export type BookableService = {
    id: number;
    name: string;
    durationMinutes: number;
};
