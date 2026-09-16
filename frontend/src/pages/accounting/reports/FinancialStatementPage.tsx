import BalanceSheetPage from './BalanceSheetPage';

/**
 * Presents balance-sheet data using the statutory report name shown in
 * published financial statements.
 */
export default function FinancialStatementPage() {
  return (
    <BalanceSheetPage
      pageTitle="Financial Statement"
      statementTitle="Statement of Financial Position"
      financialStatementStyle
    />
  );
}
