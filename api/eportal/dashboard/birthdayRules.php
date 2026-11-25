<?php
/*
 * Birthday wish rules, shared by birthdays.php, sendBirthdayWish.php and birthdayWishes.php.
 *
 *  - A wish can be sent from WISH_ADVANCE_DAYS before the birthday up to the birthday itself
 *    (the same window the Upcoming Birthdays card shows).
 *  - One wish per colleague per birthday: a wish counts for the birthday whose window it was sent in.
 *  - The birthday person sees wishes from their birthday onwards, for WISH_SHOW_DAYS days.
 *
 * Dates are worked out in Oracle with ADD_MONTHS, so 29-Feb birthdays fall on 28-Feb
 * in other years and the window crosses New Year correctly.
 */

const WISH_ADVANCE_DAYS = 7;
const WISH_SHOW_DAYS    = 30;

/* Most recent birthday on or before today, for a BIRTH_DATE column. */
function lastBirthdaySql(string $col): string
{
    return "ADD_MONTHS(TRUNC($col), 12 * FLOOR(MONTHS_BETWEEN(TRUNC(SYSDATE), TRUNC($col)) / 12))";
}

/* Next birthday on or after today (today itself when it is the birthday). */
function nextBirthdaySql(string $col): string
{
    $last = lastBirthdaySql($col);
    return "(CASE WHEN $last = TRUNC(SYSDATE) THEN $last ELSE ADD_MONTHS($last, 12) END)";
}

/* First day a wish for the next birthday can be sent. */
function wishWindowStartSql(string $col): string
{
    return "(" . nextBirthdaySql($col) . " - " . WISH_ADVANCE_DAYS . ")";
}
