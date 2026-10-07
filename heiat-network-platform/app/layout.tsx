import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  title: 'شبکه تجربه هیأت',
  description: 'صاحب مسئله را به صاحب تجربه وصل می‌کنیم.',
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="fa" dir="rtl">
      <body>{children}</body>
    </html>
  );
}
