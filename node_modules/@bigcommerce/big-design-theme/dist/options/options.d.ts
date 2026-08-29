export interface ThemeOptions {
    htmlFontSize: number;
}
declare class Options {
    private options;
    getOptions(): {
        htmlFontSize: number;
    };
    setOptions(newOptions: Partial<ThemeOptions>): {
        htmlFontSize: number;
    };
}
export declare const themeOptions: Options;
export {};
//# sourceMappingURL=options.d.ts.map