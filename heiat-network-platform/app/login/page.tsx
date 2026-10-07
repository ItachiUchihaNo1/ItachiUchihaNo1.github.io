import { getCurrentUser } from '@/lib/auth';
import { redirect } from 'next/navigation';
import LoginForm from './LoginForm';

export default async function LoginPage() {
  const user = await getCurrentUser();
  if (user) redirect('/');
  return <div className="auth-shell"><LoginForm /></div>;
}
