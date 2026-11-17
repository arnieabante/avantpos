<?php

namespace App\Enums;

/**
 * Numbered documents and the scope their series belongs to.
 *
 * BIR expects a gapless, non-resettable invoice series per machine, so POS
 * documents are numbered per terminal.
 */
enum DocumentType: string
{
    case SalesInvoice = 'sales_invoice';
    case Refund = 'refund';
    case PurchaseOrder = 'purchase_order';
    case GoodsReceipt = 'goods_receipt';
    case StockAdjustment = 'stock_adjustment';
    case StockTransfer = 'stock_transfer';
    case StockCount = 'stock_count';
    case SupplierBill = 'supplier_bill';
    case SupplierPayment = 'supplier_payment';
    case CustomerPayment = 'customer_payment';
    case Expense = 'expense';
    case ZReading = 'z_reading';
    case XReading = 'x_reading';

    public function scope(): DocumentScope
    {
        return match ($this) {
            self::SalesInvoice, self::Refund, self::ZReading, self::XReading => DocumentScope::Terminal,
            default => DocumentScope::Tenant,
        };
    }

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::SalesInvoice => '',
            self::Refund => 'RF-',
            self::PurchaseOrder => 'PO-',
            self::GoodsReceipt => 'GR-',
            self::StockAdjustment => 'ADJ-',
            self::StockTransfer => 'TR-',
            self::StockCount => 'SC-',
            self::SupplierBill => 'BILL-',
            self::SupplierPayment => 'SP-',
            self::CustomerPayment => 'CP-',
            self::Expense => 'EXP-',
            self::ZReading => 'Z-',
            self::XReading => 'X-',
        };
    }
}
