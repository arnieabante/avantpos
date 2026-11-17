import '@testing-library/jest-dom/vitest'
import { configure } from '@testing-library/react'

// Pages are lazy-loaded; their first import compiles on demand in the test runner,
// which can take longer than Testing Library's default 1 s wait.
configure({ asyncUtilTimeout: 10000 })
