<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Attendance') }}
        </h2>
    </x-slot>

    @php
        $isCheckedIn = ($latestScan['action'] ?? null) === 'check_in';
        $statusLabel = $isCheckedIn ? 'Currently Checked In' : 'Currently Checked Out';
        $statusClasses = $isCheckedIn
            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-100'
            : 'bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-100';
    @endphp

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100 space-y-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold">Trainer Attendance</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                Trainer attendance is recorded only through RFID card scans in production.
                            </p>
                        </div>
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $statusClasses }}">
                            {{ $statusLabel }}
                        </span>
                    </div>

                    <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                        QR and manual attendance have been retired. Working-day tracking and reporting now rely on RFID attendance only.
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="text-sm text-gray-500 dark:text-gray-400">Last Action</div>
                            <div class="mt-2 text-base font-semibold capitalize">
                                {{ str_replace('_', ' ', $latestScan['action'] ?? 'check_out') }}
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="text-sm text-gray-500 dark:text-gray-400">Last Recorded Time</div>
                            <div class="mt-2 text-base font-semibold">
                                {{ !empty($latestScan['timestamp']) ? \Carbon\Carbon::parse($latestScan['timestamp'])->format('M d, Y h:i A') : 'No attendance yet' }}
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="text-sm text-gray-500 dark:text-gray-400">Attendance Source</div>
                            <div class="mt-2 text-base font-semibold uppercase">
                                {{ $latestScan['source'] ?? 'RFID' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100 space-y-4">
                    <h4 class="text-base font-semibold">Recent RFID Activity</h4>
                    <ul class="space-y-3 text-sm">
                        @forelse ($recentScans as $scan)
                            <li class="flex items-center justify-between rounded-md border border-gray-200 dark:border-gray-700 px-3 py-2">
                                <span class="font-medium capitalize">{{ str_replace('_', ' ', $scan['action']) }}</span>
                                <span class="text-gray-500 dark:text-gray-400">
                                    {{ \Carbon\Carbon::parse($scan['timestamp'])->format('M d, Y h:i A') }}
                                </span>
                            </li>
                        @empty
                            <li class="text-gray-500 dark:text-gray-400">No RFID attendance recorded yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
