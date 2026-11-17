import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { FormField, Input, Select } from '@/components/ui'
import { settingsApi, settingsKeys } from '../api'
import type { AddressInput } from '../types'

type Errors = Partial<Record<keyof AddressInput, string>>

interface AddressFieldsProps {
  value: AddressInput
  onChange: (next: AddressInput) => void
  errors?: Errors
  disabled?: boolean
}

/** Select value for "no province": NCR cities and independent cities (e.g. Isabela, Cotabato). */
const NO_PROVINCE = '__none__'
const forever = { staleTime: Infinity, gcTime: 30 * 60_000 }

/**
 * Philippine address picker using PSGC codes:
 * Region → Province (or none) → City/Municipality → Barangay, plus street and ZIP.
 * Choosing a level clears the levels below it.
 */
export function AddressFields({ value, onChange, errors = {}, disabled }: AddressFieldsProps) {
  // An existing address with a city but no province was saved with "no province".
  const [noProvince, setNoProvince] = useState(() => !value.province_code && value.city_code !== '')
  const set = (patch: Partial<AddressInput>) => onChange({ ...value, ...patch })

  const regions = useQuery({ queryKey: settingsKeys.psgc('regions', ''), queryFn: settingsApi.regions, ...forever })

  const provinces = useQuery({
    queryKey: settingsKeys.psgc('provinces', value.region_code),
    queryFn: () => settingsApi.provinces(value.region_code),
    enabled: value.region_code !== '',
    ...forever,
  })

  // With a province: its cities. Without: the region's cities that have no province.
  const cities = useQuery({
    queryKey: settingsKeys.psgc('cities', value.province_code || `region:${value.region_code}`),
    queryFn: () => settingsApi.cities(value.province_code ? { province_code: value.province_code } : { region_code: value.region_code }),
    enabled: value.region_code !== '' && (value.province_code !== '' || noProvince),
    ...forever,
  })

  const barangays = useQuery({
    queryKey: settingsKeys.psgc('barangays', value.city_code),
    queryFn: () => settingsApi.barangays(value.city_code),
    enabled: value.city_code !== '',
    ...forever,
  })

  const hasProvinces = (provinces.data?.length ?? 0) > 0
  const citiesReady = value.region_code !== '' && (value.province_code !== '' || noProvince)

  return (
    <div className="space-y-4">
      <FormField label="Street address" hint="House/unit no., building, street" error={errors.address_line}>
        <Input value={value.address_line} onChange={(e) => set({ address_line: e.target.value })} disabled={disabled} autoComplete="street-address" />
      </FormField>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="Region" error={errors.region_code}>
          <Select
            value={value.region_code}
            disabled={disabled || regions.isPending}
            onChange={(e) => {
              setNoProvince(false)
              set({ region_code: e.target.value, province_code: '', city_code: '', barangay_code: '' })
            }}
          >
            <option value="">{regions.isPending ? 'Loading…' : 'Select region…'}</option>
            {regions.data?.map((r) => (
              <option key={r.code} value={r.code}>
                {r.region_name ? `${r.region_name} – ${r.name}` : r.name}
              </option>
            ))}
          </Select>
        </FormField>

        <FormField label="Province" error={errors.province_code}>
          <Select
            value={noProvince ? NO_PROVINCE : value.province_code}
            disabled={disabled || !value.region_code || provinces.isPending}
            onChange={(e) => {
              const choice = e.target.value
              setNoProvince(choice === NO_PROVINCE)
              set({ province_code: choice === NO_PROVINCE ? '' : choice, city_code: '', barangay_code: '' })
            }}
          >
            <option value="">{value.region_code ? 'Select province…' : 'Select a region first'}</option>
            {provinces.data?.map((p) => (
              <option key={p.code} value={p.code}>
                {p.name}
              </option>
            ))}
            {value.region_code && <option value={NO_PROVINCE}>{hasProvinces ? 'None (independent city)' : 'None (NCR / no province)'}</option>}
          </Select>
        </FormField>

        <FormField label="City / municipality" error={errors.city_code}>
          <Select
            value={value.city_code}
            disabled={disabled || !citiesReady || cities.isPending}
            onChange={(e) => set({ city_code: e.target.value, barangay_code: '' })}
          >
            <option value="">{citiesReady && cities.isFetching ? 'Loading…' : 'Select city / municipality…'}</option>
            {cities.data?.map((c) => (
              <option key={c.code} value={c.code}>
                {c.name}
              </option>
            ))}
          </Select>
        </FormField>

        <FormField label="Barangay" error={errors.barangay_code}>
          <Select
            value={value.barangay_code}
            disabled={disabled || !value.city_code || barangays.isPending}
            onChange={(e) => set({ barangay_code: e.target.value })}
          >
            <option value="">{value.city_code && barangays.isFetching ? 'Loading…' : 'Select barangay…'}</option>
            {barangays.data?.map((b) => (
              <option key={b.code} value={b.code}>
                {b.name}
              </option>
            ))}
          </Select>
        </FormField>
      </div>

      <FormField label="ZIP code" error={errors.postal_code} className="sm:w-40">
        <Input
          value={value.postal_code}
          onChange={(e) => set({ postal_code: e.target.value.replace(/\D/g, '').slice(0, 4) })}
          inputMode="numeric"
          autoComplete="postal-code"
          disabled={disabled}
        />
      </FormField>
    </div>
  )
}
