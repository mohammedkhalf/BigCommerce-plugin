import React from 'react';
interface HeaderProps {
    date?: Date;
    nextMonthButtonDisabled?: boolean;
    months: string[];
    prevMonthButtonDisabled?: boolean;
    decreaseMonth?(): void;
    increaseMonth?(): void;
}
declare const Header: React.FC<HeaderProps>;
export default Header;
//# sourceMappingURL=Header.d.ts.map