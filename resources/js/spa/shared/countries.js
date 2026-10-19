/*
| Countries, their calling codes and flags — for the country picker and the phone
| field (2026-10-12, HR's request: "a drop down with flag and a search bar").
|
| Generated once from libphonenumber-js 1.12.10 (calling codes) and Node's ICU
| English region names; static so the browser downloads no phone library. Where
| several countries share a code (+1, +7, +44…), `main: false` marks the ones that
| are not the code's main country, so a stored "+1 …" opens on the United States.
|
| Flags are flag-icons 7.5.0 (MIT), copied to public/flags/4x3 and loaded lazily as
| images: emoji flags do not render on Windows, which is what the operators use.
|
| The employee's COUNTRY is stored as the English name below ("Bahrain"), the same
| free text the column always held and that the ZKAccess import and export carry.
*/
const ROWS = [
    ['AF', "Afghanistan", '93'],
    ['AX', "Åland Islands", '358', false],
    ['AL', "Albania", '355'],
    ['DZ', "Algeria", '213'],
    ['AS', "American Samoa", '1', false],
    ['AD', "Andorra", '376'],
    ['AO', "Angola", '244'],
    ['AI', "Anguilla", '1', false],
    ['AG', "Antigua & Barbuda", '1', false],
    ['AR', "Argentina", '54'],
    ['AM', "Armenia", '374'],
    ['AW', "Aruba", '297'],
    ['AU', "Australia", '61'],
    ['AT', "Austria", '43'],
    ['AZ', "Azerbaijan", '994'],
    ['BS', "Bahamas", '1', false],
    ['BH', "Bahrain", '973'],
    ['BD', "Bangladesh", '880'],
    ['BB', "Barbados", '1', false],
    ['BY', "Belarus", '375'],
    ['BE', "Belgium", '32'],
    ['BZ', "Belize", '501'],
    ['BJ', "Benin", '229'],
    ['BM', "Bermuda", '1', false],
    ['BT', "Bhutan", '975'],
    ['BO', "Bolivia", '591'],
    ['BA', "Bosnia & Herzegovina", '387'],
    ['BW', "Botswana", '267'],
    ['BR', "Brazil", '55'],
    ['IO', "British Indian Ocean Territory", '246'],
    ['VG', "British Virgin Islands", '1', false],
    ['BN', "Brunei", '673'],
    ['BG', "Bulgaria", '359'],
    ['BF', "Burkina Faso", '226'],
    ['BI', "Burundi", '257'],
    ['KH', "Cambodia", '855'],
    ['CM', "Cameroon", '237'],
    ['CA', "Canada", '1', false],
    ['CV', "Cape Verde", '238'],
    ['BQ', "Caribbean Netherlands", '599', false],
    ['KY', "Cayman Islands", '1', false],
    ['CF', "Central African Republic", '236'],
    ['TD', "Chad", '235'],
    ['CL', "Chile", '56'],
    ['CN', "China", '86'],
    ['CX', "Christmas Island", '61', false],
    ['CC', "Cocos (Keeling) Islands", '61', false],
    ['CO', "Colombia", '57'],
    ['KM', "Comoros", '269'],
    ['CG', "Congo - Brazzaville", '242'],
    ['CD', "Congo - Kinshasa", '243'],
    ['CK', "Cook Islands", '682'],
    ['CR', "Costa Rica", '506'],
    ['CI', "Côte d’Ivoire", '225'],
    ['HR', "Croatia", '385'],
    ['CU', "Cuba", '53'],
    ['CW', "Curaçao", '599'],
    ['CY', "Cyprus", '357'],
    ['CZ', "Czechia", '420'],
    ['DK', "Denmark", '45'],
    ['DJ', "Djibouti", '253'],
    ['DM', "Dominica", '1', false],
    ['DO', "Dominican Republic", '1', false],
    ['EC', "Ecuador", '593'],
    ['EG', "Egypt", '20'],
    ['SV', "El Salvador", '503'],
    ['GQ', "Equatorial Guinea", '240'],
    ['ER', "Eritrea", '291'],
    ['EE', "Estonia", '372'],
    ['SZ', "Eswatini", '268'],
    ['ET', "Ethiopia", '251'],
    ['FK', "Falkland Islands", '500'],
    ['FO', "Faroe Islands", '298'],
    ['FJ', "Fiji", '679'],
    ['FI', "Finland", '358'],
    ['FR', "France", '33'],
    ['GF', "French Guiana", '594'],
    ['PF', "French Polynesia", '689'],
    ['GA', "Gabon", '241'],
    ['GM', "Gambia", '220'],
    ['GE', "Georgia", '995'],
    ['DE', "Germany", '49'],
    ['GH', "Ghana", '233'],
    ['GI', "Gibraltar", '350'],
    ['GR', "Greece", '30'],
    ['GL', "Greenland", '299'],
    ['GD', "Grenada", '1', false],
    ['GP', "Guadeloupe", '590'],
    ['GU', "Guam", '1', false],
    ['GT', "Guatemala", '502'],
    ['GG', "Guernsey", '44', false],
    ['GN', "Guinea", '224'],
    ['GW', "Guinea-Bissau", '245'],
    ['GY', "Guyana", '592'],
    ['HT', "Haiti", '509'],
    ['HN', "Honduras", '504'],
    ['HK', "Hong Kong SAR China", '852'],
    ['HU', "Hungary", '36'],
    ['IS', "Iceland", '354'],
    ['IN', "India", '91'],
    ['ID', "Indonesia", '62'],
    ['IR', "Iran", '98'],
    ['IQ', "Iraq", '964'],
    ['IE', "Ireland", '353'],
    ['IM', "Isle of Man", '44', false],
    ['IL', "Israel", '972'],
    ['IT', "Italy", '39'],
    ['JM', "Jamaica", '1', false],
    ['JP', "Japan", '81'],
    ['JE', "Jersey", '44', false],
    ['JO', "Jordan", '962'],
    ['KZ', "Kazakhstan", '7', false],
    ['KE', "Kenya", '254'],
    ['KI', "Kiribati", '686'],
    ['XK', "Kosovo", '383'],
    ['KW', "Kuwait", '965'],
    ['KG', "Kyrgyzstan", '996'],
    ['LA', "Laos", '856'],
    ['LV', "Latvia", '371'],
    ['LB', "Lebanon", '961'],
    ['LS', "Lesotho", '266'],
    ['LR', "Liberia", '231'],
    ['LY', "Libya", '218'],
    ['LI', "Liechtenstein", '423'],
    ['LT', "Lithuania", '370'],
    ['LU', "Luxembourg", '352'],
    ['MO', "Macao SAR China", '853'],
    ['MG', "Madagascar", '261'],
    ['MW', "Malawi", '265'],
    ['MY', "Malaysia", '60'],
    ['MV', "Maldives", '960'],
    ['ML', "Mali", '223'],
    ['MT', "Malta", '356'],
    ['MH', "Marshall Islands", '692'],
    ['MQ', "Martinique", '596'],
    ['MR', "Mauritania", '222'],
    ['MU', "Mauritius", '230'],
    ['YT', "Mayotte", '262', false],
    ['MX', "Mexico", '52'],
    ['FM', "Micronesia", '691'],
    ['MD', "Moldova", '373'],
    ['MC', "Monaco", '377'],
    ['MN', "Mongolia", '976'],
    ['ME', "Montenegro", '382'],
    ['MS', "Montserrat", '1', false],
    ['MA', "Morocco", '212'],
    ['MZ', "Mozambique", '258'],
    ['MM', "Myanmar (Burma)", '95'],
    ['NA', "Namibia", '264'],
    ['NR', "Nauru", '674'],
    ['NP', "Nepal", '977'],
    ['NL', "Netherlands", '31'],
    ['NC', "New Caledonia", '687'],
    ['NZ', "New Zealand", '64'],
    ['NI', "Nicaragua", '505'],
    ['NE', "Niger", '227'],
    ['NG', "Nigeria", '234'],
    ['NU', "Niue", '683'],
    ['NF', "Norfolk Island", '672'],
    ['KP', "North Korea", '850'],
    ['MK', "North Macedonia", '389'],
    ['MP', "Northern Mariana Islands", '1', false],
    ['NO', "Norway", '47'],
    ['OM', "Oman", '968'],
    ['PK', "Pakistan", '92'],
    ['PW', "Palau", '680'],
    ['PS', "Palestinian Territories", '970'],
    ['PA', "Panama", '507'],
    ['PG', "Papua New Guinea", '675'],
    ['PY', "Paraguay", '595'],
    ['PE', "Peru", '51'],
    ['PH', "Philippines", '63'],
    ['PL', "Poland", '48'],
    ['PT', "Portugal", '351'],
    ['PR', "Puerto Rico", '1', false],
    ['QA', "Qatar", '974'],
    ['RE', "Réunion", '262'],
    ['RO', "Romania", '40'],
    ['RU', "Russia", '7'],
    ['RW', "Rwanda", '250'],
    ['WS', "Samoa", '685'],
    ['SM', "San Marino", '378'],
    ['ST', "São Tomé & Príncipe", '239'],
    ['SA', "Saudi Arabia", '966'],
    ['SN', "Senegal", '221'],
    ['RS', "Serbia", '381'],
    ['SC', "Seychelles", '248'],
    ['SL', "Sierra Leone", '232'],
    ['SG', "Singapore", '65'],
    ['SX', "Sint Maarten", '1', false],
    ['SK', "Slovakia", '421'],
    ['SI', "Slovenia", '386'],
    ['SB', "Solomon Islands", '677'],
    ['SO', "Somalia", '252'],
    ['ZA', "South Africa", '27'],
    ['KR', "South Korea", '82'],
    ['SS', "South Sudan", '211'],
    ['ES', "Spain", '34'],
    ['LK', "Sri Lanka", '94'],
    ['BL', "St. Barthélemy", '590', false],
    ['SH', "St. Helena", '290'],
    ['KN', "St. Kitts & Nevis", '1', false],
    ['LC', "St. Lucia", '1', false],
    ['MF', "St. Martin", '590', false],
    ['PM', "St. Pierre & Miquelon", '508'],
    ['VC', "St. Vincent & Grenadines", '1', false],
    ['SD', "Sudan", '249'],
    ['SR', "Suriname", '597'],
    ['SJ', "Svalbard & Jan Mayen", '47', false],
    ['SE', "Sweden", '46'],
    ['CH', "Switzerland", '41'],
    ['SY', "Syria", '963'],
    ['TW', "Taiwan", '886'],
    ['TJ', "Tajikistan", '992'],
    ['TZ', "Tanzania", '255'],
    ['TH', "Thailand", '66'],
    ['TL', "Timor-Leste", '670'],
    ['TG', "Togo", '228'],
    ['TK', "Tokelau", '690'],
    ['TO', "Tonga", '676'],
    ['TT', "Trinidad & Tobago", '1', false],
    ['TN', "Tunisia", '216'],
    ['TR', "Türkiye", '90'],
    ['TM', "Turkmenistan", '993'],
    ['TC', "Turks & Caicos Islands", '1', false],
    ['TV', "Tuvalu", '688'],
    ['VI', "U.S. Virgin Islands", '1', false],
    ['UG', "Uganda", '256'],
    ['UA', "Ukraine", '380'],
    ['AE', "United Arab Emirates", '971'],
    ['GB', "United Kingdom", '44'],
    ['US', "United States", '1'],
    ['UY', "Uruguay", '598'],
    ['UZ', "Uzbekistan", '998'],
    ['VU', "Vanuatu", '678'],
    ['VA', "Vatican City", '39', false],
    ['VE', "Venezuela", '58'],
    ['VN', "Vietnam", '84'],
    ['WF', "Wallis & Futuna", '681'],
    ['EH', "Western Sahara", '212', false],
    ['YE', "Yemen", '967'],
    ['ZM', "Zambia", '260'],
    ['ZW', "Zimbabwe", '263'],
];

/*
| The calling code an empty phone field opens on when the employee has no country
| chosen. Bahrain: every terminal and employer on this system is there (2026-10-12).
| A second-country deployment should make this a company setting rather than edit it.
*/
export const DEFAULT_COUNTRY = 'BH';

export const COUNTRIES = ROWS.map(([code, name, dial, main = true]) => ({ code, name, dial, main }));

const BY_CODE = new Map(COUNTRIES.map((c) => [c.code, c]));
const BY_NAME = new Map(COUNTRIES.map((c) => [c.name.toLowerCase(), c]));

/** The country a stored value names — by English name or ISO code, any case. */
export function findCountry(value) {
    const v = String(value ?? '').trim();
    if (!v) return null;
    return BY_NAME.get(v.toLowerCase()) ?? BY_CODE.get(v.toUpperCase()) ?? null;
}

export function countryByCode(code) {
    return BY_CODE.get(code) ?? null;
}

/** The longest-first codes, so "+9733…" is Bahrain (973) and not a 9- or 97- prefix. */
const DIALS = [...new Set(COUNTRIES.map((c) => c.dial))].sort((a, b) => b.length - a.length);

/**
 * Splits a stored phone number into its country and the rest: "+973 3344 5566" →
 * { country: Bahrain, number: "3344 5566" }. A number with no "+" (typed before the
 * picker existed, or sent by an integration) keeps its text and gets no country, so
 * nothing anybody entered is rewritten by opening the form.
 */
export function splitPhone(value) {
    const v = String(value ?? '').trim();
    if (!v.startsWith('+')) return { country: null, number: v };
    const digits = v.slice(1).replace(/\D/g, '');
    const dial = DIALS.find((d) => digits.startsWith(d));
    if (!dial) return { country: null, number: v };
    const country = COUNTRIES.find((c) => c.dial === dial && c.main) ?? COUNTRIES.find((c) => c.dial === dial);
    // Drop exactly the code's digits from the front, keeping the person's spacing after it.
    let seen = 0;
    let i = 1;
    while (i < v.length && seen < dial.length) {
        if (/\d/.test(v[i])) seen += 1;
        i += 1;
    }
    return { country, number: v.slice(i).trim() };
}

/** The stored form: "+973 33445566", or '' when there is no number. */
export function joinPhone(country, number) {
    const n = String(number ?? '').trim();
    if (!n) return '';
    return country ? `+${country.dial} ${n}` : n;
}
