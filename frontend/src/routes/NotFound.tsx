import { Link } from 'react-router-dom'

export default function NotFound() {
  return (
    <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
      <div className="text-center space-y-4">
        <h1 className="text-4xl font-semibold text-gray-900">404</h1>
        <p className="text-gray-600">That page doesn&apos;t exist.</p>
        <Link to="/" className="inline-block text-blue-600 hover:underline">
          Back home
        </Link>
      </div>
    </div>
  )
}
