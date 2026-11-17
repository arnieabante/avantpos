import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { ConfirmProvider, useConfirm } from './ConfirmDialog'
import { DataTable } from './DataTable'
import { Modal } from './Modal'
import { Pagination } from './Pagination'

describe('Modal', () => {
  it('focuses inside, closes on Escape and restores focus', async () => {
    function Harness() {
      const [open, setOpen] = useState(false)
      return (
        <>
          <button onClick={() => setOpen(true)}>Open</button>
          <Modal open={open} onClose={() => setOpen(false)} title="Edit product">
            <input aria-label="Name" />
          </Modal>
        </>
      )
    }

    const user = userEvent.setup()
    render(<Harness />)

    await user.click(screen.getByRole('button', { name: 'Open' }))
    expect(screen.getByRole('dialog', { name: 'Edit product' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Close' })).toHaveFocus()

    await user.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Open' })).toHaveFocus()
  })
})

describe('useConfirm', () => {
  it('resolves true on confirm and false on cancel', async () => {
    const results: boolean[] = []
    function Harness() {
      const confirm = useConfirm()
      return <button onClick={async () => results.push(await confirm({ title: 'Void this sale?', confirmLabel: 'Void', danger: true }))}>Void</button>
    }

    const user = userEvent.setup()
    render(
      <ConfirmProvider>
        <Harness />
      </ConfirmProvider>,
    )

    await user.click(screen.getByRole('button', { name: 'Void' }))
    await user.click(screen.getByRole('button', { name: 'Cancel' }))
    await user.click(screen.getByRole('button', { name: 'Void' }))
    await user.click(screen.getAllByRole('button', { name: 'Void' })[1]!)

    expect(results).toEqual([false, true])
  })
})

describe('Pagination', () => {
  it('shows the range and changes page', async () => {
    const onPageChange = vi.fn()
    const user = userEvent.setup()
    render(<Pagination meta={{ current_page: 2, per_page: 25, total: 60, last_page: 3 }} onPageChange={onPageChange} />)

    expect(screen.getByRole('navigation', { name: 'Pagination' })).toHaveTextContent('Showing 26–50 of 60')
    expect(screen.getByRole('button', { name: '2' })).toHaveAttribute('aria-current', 'page')

    await user.click(screen.getByRole('button', { name: 'Next page' }))
    expect(onPageChange).toHaveBeenCalledWith(3)
  })

  it('renders nothing for empty results', () => {
    const { container } = render(<Pagination meta={{ total: 0 }} onPageChange={() => {}} />)
    expect(container).toBeEmptyDOMElement()
  })
})

describe('DataTable', () => {
  const columns = [{ key: 'name', header: 'Name', render: (row: { id: number; name: string }) => row.name }]

  it('renders rows', () => {
    render(<DataTable columns={columns} rows={[{ id: 1, name: 'Helmet' }]} rowKey={(r) => r.id} />)
    expect(screen.getByRole('cell', { name: 'Helmet' })).toBeInTheDocument()
  })

  it('renders the empty state', () => {
    render(<DataTable columns={columns} rows={[]} rowKey={(r) => r.id} empty={<p>No products yet</p>} />)
    expect(screen.getByText('No products yet')).toBeInTheDocument()
  })
})
