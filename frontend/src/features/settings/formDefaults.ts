import { WEEKDAYS, type AddressInput, type DayHours, type OperatingHours } from './types'

/* Form state helpers shared by the settings forms. */

export const DEFAULT_DAY: DayHours = { closed: false, open: '08:00', close: '20:00' }

/** Default week for a new branch: open daily 8 AM – 8 PM. */
export function defaultOperatingHours(): OperatingHours {
  return Object.fromEntries(WEEKDAYS.map((d) => [d.key, { ...DEFAULT_DAY }])) as OperatingHours
}

type ApiAddress = {
  address_line: string | null
  region_code: string | null
  province_code: string | null
  city_code: string | null
  barangay_code: string | null
  postal_code: string | null
}

/** Form state from an API address. */
export function addressFromApi(address: ApiAddress | null | undefined): AddressInput {
  return {
    address_line: address?.address_line ?? '',
    region_code: address?.region_code ?? '',
    province_code: address?.province_code ?? '',
    city_code: address?.city_code ?? '',
    barangay_code: address?.barangay_code ?? '',
    postal_code: address?.postal_code ?? '',
  }
}

export const emptyAddress: AddressInput = addressFromApi(null)
