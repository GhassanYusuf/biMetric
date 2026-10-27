/**
 * @ahl/id-card-reader: read Bahrain smart ID cards (CPR) in the browser through iGA's "GCC CardRead Server"
 * (SCardReadServer), which runs on the PC with the card reader. On that machine it exposes:
 *   REST  POST http://localhost:5050/api/operation/ReadCard
 *   WS         ws://localhost:5060/SCardRead
 * Both are described by the vendor samples (Samples/RESTfull.html, Samples/WebSocket.html). No backend is needed
 * to read a card; newer Bahrain cards need the service update in setup/ (see README).
 *
 * Privacy: the reader is only contacted on this PC. The card's fields (name, birth date, gender, photo and the CPR
 * number) go wherever the host sends them, usually its own server when staff save a form. Nothing here logs card
 * data, and the CPR is never put in a file name or a status message.
 */

export interface CardReaderConfig {
  /** REST endpoint of the service. */
  restUrl: string
  /** WebSocket endpoint of the service. */
  wsUrl: string
  /** Milliseconds between silent reads when the service has no WebSocket (REST polling fallback). */
  pollMs: number
}

const settings: CardReaderConfig = {
  restUrl: 'http://localhost:5050/api/operation/ReadCard',
  wsUrl: 'ws://localhost:5060/SCardRead',
  pollMs: 2000,
}

/** Change the endpoints per deployment (for example the service's https / wss endpoints on an https site). */
export function configureCardReader(config: Partial<CardReaderConfig>) {
  // Unset values (for example an empty environment variable) keep the default.
  if (config.restUrl) settings.restUrl = config.restUrl
  if (config.wsUrl) settings.wsUrl = config.wsUrl
  if (config.pollMs && config.pollMs > 0) settings.pollMs = config.pollMs
}

export const cardReaderConfig = (): Readonly<CardReaderConfig> => ({ ...settings })

/** What a form can use from a card. Every field may be missing on some card versions. */
export interface CardData {
  /** Opaque fingerprint of the card holder, only for "is this the same card?". Not the CPR number. */
  cardKey: string | null
  /** Bahrain personal number, nine Latin digits; null when the card does not give a valid one. */
  cpr: string | null
  nameAr: string | null
  nameEn: string | null
  /** ISO yyyy-mm-dd */
  birthDate: string | null
  gender: 'male' | 'female' | null
  /** Home address as one line (flat, building, road, block, area), in Arabic and English as the card gives it. */
  addressAr: string | null
  addressEn: string | null
  photo: File | null
}

/**
 * not_running    the reader service does not answer on this PC
 * reader_off     the service answers but the Windows Smart Card service or the reader is not ready
 * no_card        no card in the reader
 * failed         a card is there but could not be read
 * service_error  anything else the service returned (unexpected answer)
 */
export type CardReaderError = 'not_running' | 'reader_off' | 'no_card' | 'failed' | 'service_error'

/**
 * The read options the vendor sample sends. Personal info gives the name, birth date, gender and CPR; address
 * details the home address (no slower). The photo only comes with ReadBiometrics (a JPEG, about 20 KB, and a read
 * of about 4 s instead of under 1 s). That
 * option also returns the signature image, which is ignored here and never leaves this module.
 */
const READ_OPTIONS = {
  ReadCardInfo: false,
  ReadPersonalInfo: true,
  ReadAddressDetails: true,
  ReadBiometrics: false,
  ReadEmploymentInfo: false,
  ReadImmigrationDetails: false,
  ReadTrafficDetails: false,
  SilentReading: false,
  ReaderIndex: -1,
  ReaderName: '',
  OutputFormat: 'JSON',
  ValidateCard: false,
}

/**
 * Read the card in the reader once. The body is a JSON string sent with a form content type, exactly
 * like the vendor sample: that keeps it a "simple" cross-origin request, so the local service never has
 * to answer a CORS preflight.
 */
export async function readCard(signal?: AbortSignal, silent = false, withPhoto = true): Promise<CardData> {
  let res: Response
  try {
    res = await fetch(settings.restUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: JSON.stringify({ ...READ_OPTIONS, SilentReading: silent, ReadBiometrics: withPhoto }),
      signal,
    })
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e
    throw cardError('not_running')
  }
  let raw: unknown
  try { raw = JSON.parse(await res.text()) } catch { throw cardError('service_error') }
  // A non-2xx answer still carries ErrorDescription on some builds; classify it, never show it raw.
  if (!res.ok) throw classify(unwrap(raw), 'service_error')
  return parseCard(raw)
}

/** Maps the service's ErrorDescription (English or Arabic, by the PC's language) to a staff message code. */
function classify(obj: Record<string, unknown>, fallback: CardReaderError = 'no_card') {
  const d = String(obj.ErrorDescription ?? obj.Description ?? '').trim()
  if (!d) return cardError(fallback)
  if (/resource manager|reader.{0,20}(not|un|no\b)|no.{0,10}reader|smart ?card service|القارئ|مدير الموارد/i.test(d)) return cardError('reader_off')
  if (/no card|not inserted|insert|removed|no smart ?card|card.{0,20}(absent|not present)|لا توجد بطاقة|أدخل البطاقة/i.test(d)) return cardError('no_card')
  if (/read|apdu|transmit|failed|unsupported|not supported|corrupt|قراءة/i.test(d)) return cardError('failed')
  return cardError(fallback === 'no_card' ? 'service_error' : fallback)
}

/** Parse the service's JSON (flat SmartcardData object, possibly wrapped) into CardData. */
export function parseCard(raw: unknown): CardData {
  const obj = unwrap(raw)
  const get = (...keys: string[]) => {
    for (const k of keys) {
      const hit = Object.keys(obj).find((x) => x.toLowerCase() === k.toLowerCase())
      const v = hit ? obj[hit] : undefined
      if (typeof v === 'string' && v.trim() && !/^data not found$/i.test(v.trim())) return v.trim()
      if (typeof v === 'number') return String(v)
    }
    return null
  }
  const nameAr = get('ArabicFullName') ?? joinNames(['ArabicFirstName', 'ArabicMiddleName2', 'ArabicMiddleName3', 'ArabicMiddleName4', 'ArabicMiddleName5', 'ArabicLastName'].map((k) => get(k)))
  const nameEn = get('EnglishFullName') ?? joinNames(['EnglishFirstName', 'EnglishMiddleName2', 'EnglishMiddleName3', 'EnglishMiddleName4', 'EnglishMiddleName5', 'EnglishLastName'].map((k) => get(k)))
  const cpr = get('IdNumber', 'CPRNumber', 'CprNo', 'IDNumber')
  if (!cpr && !nameAr && !nameEn) {
    // A card was detected (the service names its country or serial) but nothing personal came back: the
    // card is there and could not be read (for example a service too old for this card version), not 'no card'.
    if (get('CardCountry', 'CardSerialNumber', 'CardVersion')) throw cardError('failed')
    throw classify(obj)
  }
  const photo64 = get('PhotoB64Encoded', 'Photo')

  return {
    cardKey: fingerprint(cpr ?? `${nameAr ?? ''}|${nameEn ?? ''}|${get('BirthDate') ?? ''}`),
    cpr: normalizeCpr(cpr),
    nameAr,
    nameEn,
    birthDate: parseDate(get('BirthDate', 'DateOfBirth', 'DOB')),
    gender: parseGender(get('Gender', 'Sex')),
    // Generic file name: the upload must not carry the CPR number.
    addressAr: oneLine(get('AddressArabic')),
    addressEn: oneLine(get('AddressEnglish')),
    photo: photo64 ? base64ToFile(photo64, 'id-card-photo') : null,
  }
}

function unwrap(raw: unknown): Record<string, unknown> {
  let o = raw
  // Some builds return a JSON string inside JSON, or wrap the data in { SmartcardData: {...} } / { Data: {...} }.
  if (typeof o === 'string') { try { o = JSON.parse(o) } catch { /* leave as is */ } }
  if (o && typeof o === 'object') {
    const r = o as Record<string, unknown>
    for (const k of ['SmartcardData', 'smartcardData', 'Data', 'data', 'Result', 'result']) {
      if (r[k] && typeof r[k] === 'object') return r[k] as Record<string, unknown>
    }
    return r
  }
  return {}
}

/** The card pads the address with runs of spaces: one tidy line, or null. */
const oneLine = (v: string | null) => (v ? v.replace(/\s+/g, ' ').trim() || null : null)

const joinNames = (parts: (string | null)[]) => {
  const s = parts.filter(Boolean).join(' ').replace(/\s+/g, ' ').trim()
  return s || null
}

/** Words that make a first name of two words: "عبد الله", "نور الدين", "Abdul Latif", "Salah Eldin". */
const COMPOUND_FIRST = /^(عبد|عبدال|abd|abdul|abdel|abdal|abu|abo|أبو|ابو)$/i
const COMPOUND_SECOND = /^(الدين|الله|الرحمن|الرحيم|الإسلام|الاسلام|eldin|aldin|uddin|el-din|al-din|allah|ullah|elrahman|alrahman|alislam)$/i

/**
 * The father's name from a card holder's full name, the Arab way: the full name is "first father grandfather
 * family", so the father is everything after the first name ("أحمد محمد علي الدوسري" → "محمد علي الدوسري").
 * Two-word first names ("عبد الله …", "نور الدين …") are kept together. Needs at least three names after the
 * first, or two (father and family); otherwise returns null rather than a guess.
 */
export function fatherNameFrom(fullName: string | null | undefined): string | null {
  const words = (fullName ?? '').trim().split(/\s+/).filter(Boolean)
  const firstLength = words.length > 1 && (COMPOUND_FIRST.test(words[0]) || COMPOUND_SECOND.test(words[1])) ? 2 : 1
  const rest = words.slice(firstLength)
  return rest.length >= 2 ? rest.join(' ') : null
}

/** Nine Latin digits (the card may use Arabic digits or separators), otherwise null. */
export function normalizeCpr(v: string | null): string | null {
  if (!v) return null
  const d = v.replace(/[٠-٩]/g, (x) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(x))).replace(/\D+/g, '')
  return /^\d{9}$/.test(d) ? d : null
}

/** FNV-1a (32-bit) as hex: enough to tell cards apart, not reversible to the number. */
function fingerprint(s: string): string {
  let h = 0x811c9dc5
  for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 0x01000193) >>> 0 }
  return h.toString(16).padStart(8, '0')
}

const MONTHS: Record<string, number> = {
  jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, sept: 9, oct: 10, nov: 11, dec: 12,
  يناير: 1, فبراير: 2, مارس: 3, أبريل: 4, ابريل: 4, مايو: 5, يونيو: 6, يوليو: 7, أغسطس: 8, اغسطس: 8, سبتمبر: 9, أكتوبر: 10, اكتوبر: 10, نوفمبر: 11, ديسمبر: 12,
}

/**
 * Birth date in any format the SDK / service is known or likely to return, as ISO yyyy-mm-dd:
 *   yyyy-MM-dd[Thh:mm[:ss]]  yyyy/MM/dd  yyyyMMdd  .NET "/Date(ms)/"
 *   dd/MM/yyyy  dd-MM-yyyy  dd.MM.yyyy  (Bahrain order; MM/dd when the first part is > 12 is impossible,
 *   so a second part > 12 means MM/dd/yyyy, and a trailing AM/PM means the .NET en-US "M/d/yyyy h:mm tt")
 *   dd-MMM-yyyy / dd MMMM yyyy (English or Arabic month names), Arabic-Indic digits.
 * Hijri-looking years (< 1900) and impossible dates return null rather than a wrong date.
 */
export function parseDate(v: string | null): string | null {
  if (!v) return null
  const s = v.replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/‏|‎/g, '').trim()
  const iso = (y: number, m: number, d: number) => {
    const dt = new Date(Date.UTC(y, m - 1, d))
    const ok = y >= 1900 && y <= 2100 && dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d
    return ok ? `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}` : null
  }
  let m = s.match(/^\/Date\((-?\d+)[^)]*\)\/$/)
  if (m) { const d = new Date(Number(m[1])); return iso(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate()) }
  m = s.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?!\d)/)
  if (m) return iso(+m[1], +m[2], +m[3])
  m = s.match(/^(\d{4})(\d{2})(\d{2})$/)
  if (m) return iso(+m[1], +m[2], +m[3])
  m = s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})(?!\d)(.*)$/)
  if (m) {
    const [a, b, y] = [+m[1], +m[2], +m[3]]
    const usClock = /\b(AM|PM)\b/i.test(m[4])
    if (b > 12 || (usClock && a <= 12)) return iso(y, a, b) // M/d/yyyy
    return iso(y, b, a) // d/M/yyyy
  }
  m = s.match(/^(\d{1,2})[\s\-/.]+([A-Za-z؀-ۿ]+)[\s\-/.,]+(\d{4})/)
  if (m) {
    const word = m[2].toLowerCase()
    const mon = MONTHS[word] ?? MONTHS[word.slice(0, 3)]
    return mon ? iso(+m[3], mon, +m[1]) : null
  }
  return null
}

/** Card gender → the application's 'male' | 'female'. Unknown values leave the field for staff to choose. */
export function parseGender(v: string | null): 'male' | 'female' | null {
  if (!v) return null
  const s = v.trim().toLowerCase().replace(/[ً-ْ]/g, '')
  if (['m', 'male', 'man', 'ذكر', 'ذ', 'م'].includes(s)) return 'male'
  if (['f', 'female', 'woman', 'أنثى', 'انثى', 'انثي', 'أنثي', 'ا', 'أ', 'ث'].includes(s)) return 'female'
  // Numeric codes: ISO/IEC 5218 (1 male, 2 female), as used by several GCC card SDKs.
  if (s === '1') return 'male'
  if (s === '2') return 'female'
  return null
}

function base64ToFile(b64: string, name: string): File | null {
  try {
    const clean = b64.replace(/^data:[^,]+,/, '').replace(/\s+/g, '')
    const bytes = Uint8Array.from(atob(clean), (c) => c.charCodeAt(0))
    // The card photo is JPEG on most cards and PNG on some; detect from the bytes.
    const png = bytes[0] === 0x89 && bytes[1] === 0x50
    const jp2 = bytes[0] === 0x00 && bytes[1] === 0x00 && bytes[2] === 0x00 && bytes[3] === 0x0c
    if (jp2) return null // JPEG 2000: browsers cannot show or upload it as a photo; the staff can add one by hand.
    return new File([bytes], `${name}.${png ? 'png' : 'jpg'}`, { type: png ? 'image/png' : 'image/jpeg' })
  } catch {
    return null
  }
}

/** Errors carry a code only: the service's own text is never shown to staff or logged. */
function cardError(code: CardReaderError) {
  return Object.assign(new Error(code), { code })
}

export const cardErrorCode = (e: unknown): CardReaderError => ((e as { code?: CardReaderError })?.code ?? 'service_error')

/**
 * Watch the reader: connects to the service's WebSocket, turns on card detection, and reads each card
 * that is inserted. When the service has no WebSocket, it polls the REST endpoint every pollMs instead.
 * Either way a card is handed to `onCard` once: reading the same card again (repeated detection events,
 * the next poll) does not overwrite what staff corrected. After the card is removed, the next card is new.
 * Returns a stop function.
 */
export function watchCards(onCard: (c: CardData) => void, onStatus: (s: 'connected' | 'disconnected', code?: CardReaderError) => void): () => void {
  let ws: WebSocket | null = null
  let stopped = false
  let retry: ReturnType<typeof setTimeout> | undefined
  let opened = false
  let polling = false
  let lastKey: string | null = null
  const readCmd = 'ReadCard' + JSON.stringify({ ...READ_OPTIONS, ReadBiometrics: true })

  const deliver = (card: CardData) => {
    if (stopped || card.cardKey === lastKey) return
    lastKey = card.cardKey
    onCard(card)
  }

  const connect = () => {
    try { ws = new WebSocket(settings.wsUrl) } catch { poll(); return }
    ws.onopen = () => { opened = true; onStatus('connected'); ws?.send('RunCardDetection') }
    ws.onmessage = (ev) => {
      const text = typeof ev.data === 'string' ? ev.data : ''
      if (!text || /ReaderNames/.test(text)) return
      if (/remov|ejected|absent/i.test(text)) { lastKey = null; return }
      // A message with card data: use it. An error answer (no card, reader off): the next card is new.
      // Otherwise a detection event ("card inserted"): ask for the data.
      let json: unknown = null
      try { json = JSON.parse(text) } catch { /* plain-text event */ }
      if (json !== null) {
        try { deliver(parseCard(json)) } catch (e) { if (cardErrorCode(e) === 'no_card') lastKey = null }
        return
      }
      if (/insert|detect|present|atr/i.test(text)) ws?.send(readCmd)
    }
    ws.onclose = () => {
      if (stopped) return
      // Never opened: this install has no WebSocket, so watch the reader over REST instead.
      if (!opened) { poll(); return }
      onStatus('disconnected', 'not_running')
      retry = setTimeout(connect, 5000)
    }
    ws.onerror = () => { /* onclose follows */ }
  }

  /**
   * REST fallback: a quick silent read (no photo) every pollMs to notice a card; for a new card, one full
   * read with the photo. Each inserted card is handed over once.
   */
  const poll = async () => {
    if (stopped || polling) return
    polling = true
    while (!stopped) {
      try {
        const quick = await readCard(undefined, true, false)
        onStatus('connected')
        if (quick.cardKey !== lastKey) {
          let full = quick
          try { full = await readCard(undefined, true, true) } catch { /* keep the quick read; staff can add a photo */ }
          deliver(full.cardKey === quick.cardKey ? full : quick)
        }
      } catch (e) {
        const code = cardErrorCode(e)
        // No card (or taken out): the next card is new again. Service or reader missing: show it.
        if (code === 'no_card') { lastKey = null; onStatus('connected') } else onStatus('disconnected', code)
      }
      await new Promise((r) => { retry = setTimeout(r, settings.pollMs) })
    }
  }

  connect()

  return () => {
    stopped = true
    clearTimeout(retry)
    try { ws?.send('CancelCardDetection') } catch { /* closed already */ }
    ws?.close()
  }
}
