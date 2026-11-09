<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Attendance') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100 space-y-6">
                    <div>
                        <h3 class="text-lg font-semibold">RFID Attendance Center</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            Attendance records, active check-ins, and reporting now come from RFID attendance only.
                        </p>
                    </div>

                    <div class="rounded-lg border border-emerald-200 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-900/20 p-5 text-sm text-emerald-900 dark:text-emerald-100">
                        QR and manual attendance flows have been retired from production. Use RFID card registration and `/api/attendance/rfid/scan` for all attendance writes.
                    </div>

                    <section class="space-y-6">
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="attendance-username">Username</label>
                                    <input id="attendance-username" type="text" placeholder="Enter username (optional)" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                                </div>
                                <div class="flex-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="attendance-start">Start Date</label>
                                    <input id="attendance-start" type="date" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                                </div>
                                <div class="flex-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="attendance-end">End Date</label>
                                    <input id="attendance-end" type="date" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100">
                                </div>
                                <div>
                                    <button type="button" id="attendance-filter" class="inline-flex items-center gap-2 px-5 py-2 bg-gray-900 text-dark rounded-md text-sm font-semibold hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800">
                                        <span>Filter</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900">
                                        <tr>
                                            <th class="px-4 py-3 text-left font-semibold">Username</th>
                                            <th class="px-4 py-3 text-left font-semibold">Role</th>
                                            <th class="px-4 py-3 text-left font-semibold">Action</th>
                                            <th class="px-4 py-3 text-left font-semibold">Timestamp</th>
                                            <th class="px-4 py-3 text-left font-semibold">Source</th>
                                            <th class="px-4 py-3 text-left font-semibold">Trainer Working Days</th>
                                        </tr>
                                    </thead>
                                    <tbody id="attendance-table" class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr>
                                            <td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                                                Use the filters above to search RFID attendance records.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-6">
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-5">
                            <div class="flex flex-col gap-2">
                                <h4 class="text-lg font-semibold">Checked-in Users</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    Active users are people whose RFID check-in exists for today and whose check-out has not been recorded yet.
                                </p>
                                <div class="flex flex-wrap gap-3 text-sm text-gray-700 dark:text-gray-200">
                                    <span class="rounded-full bg-white dark:bg-gray-800 px-3 py-1">Total members: <strong id="total-members">{{ $totalMembers }}</strong></span>
                                    <span class="rounded-full bg-white dark:bg-gray-800 px-3 py-1">Active check-ins: <strong id="active-members">0</strong></span>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900">
                                        <tr>
                                            <th class="px-4 py-3 text-left font-semibold">Username</th>
                                            <th class="px-4 py-3 text-left font-semibold">Role</th>
                                            <th class="px-4 py-3 text-left font-semibold">Last Scan</th>
                                            <th class="px-4 py-3 text-left font-semibold">Source</th>
                                            <th class="px-4 py-3 text-left font-semibold">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="checked-in-table" class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <tr>
                                            <td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                                                No active RFID check-ins yet.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script>
        const attendanceTable = document.getElementById('attendance-table');
        const usernameInput = document.getElementById('attendance-username');
        const startDateInput = document.getElementById('attendance-start');
        const endDateInput = document.getElementById('attendance-end');
        const filterButton = document.getElementById('attendance-filter');
        const totalMembers = document.getElementById('total-members');
        const activeMembers = document.getElementById('active-members');
        const checkedInTable = document.getElementById('checked-in-table');

        const formatTimestamp = (isoString) => {
            const date = new Date(isoString);
            return date.toLocaleString('en-US', {
                month: 'short',
                day: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        };

        const renderAttendance = (records) => {
            if (!records.length) {
                attendanceTable.innerHTML = `
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            No RFID attendance records found for the selected filters.
                        </td>
                    </tr>
                `;
                return;
            }

            attendanceTable.innerHTML = records.map((record) => `
                <tr>
                    <td class="px-4 py-3">${record.username}</td>
                    <td class="px-4 py-3 capitalize">${record.role}</td>
                    <td class="px-4 py-3">${record.action.replace('_', ' ')}</td>
                    <td class="px-4 py-3">${formatTimestamp(record.timestamp)}</td>
                    <td class="px-4 py-3 uppercase">${record.source}</td>
                    <td class="px-4 py-3 font-semibold">${record.total_check_in_days}</td>
                </tr>
            `).join('');
        };

        const renderCheckedIn = (data) => {
            totalMembers.textContent = data.total_members;
            activeMembers.textContent = data.active_count;

            if (!data.active_users.length) {
                checkedInTable.innerHTML = `
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                            No active RFID check-ins yet.
                        </td>
                    </tr>
                `;
                return;
            }

            checkedInTable.innerHTML = data.active_users.map((user) => `
                <tr>
                    <td class="px-4 py-3">${user.username}</td>
                    <td class="px-4 py-3 capitalize">${user.role}</td>
                    <td class="px-4 py-3">${formatTimestamp(user.last_scan)}</td>
                    <td class="px-4 py-3 uppercase">${user.source}</td>
                    <td class="px-4 py-3">
                        <span class="rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-100 px-3 py-1 text-xs font-semibold">
                            ${user.status}
                        </span>
                    </td>
                </tr>
            `).join('');
        };

        const fetchAttendance = async () => {
            const params = new URLSearchParams();
            if (usernameInput.value.trim()) {
                params.set('username', usernameInput.value.trim());
            }
            if (startDateInput.value) {
                params.set('start_date', startDateInput.value);
            }
            if (endDateInput.value) {
                params.set('end_date', endDateInput.value);
            }

            const response = await fetch(`{{ route('attendance.records') }}?${params.toString()}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();
            renderAttendance(data.records || []);
        };

        const fetchCheckedIn = async () => {
            const response = await fetch(`{{ route('attendance.checked-in') }}`, {
                headers: { 'Accept': 'application/json' },
            });
            const data = await response.json();
            renderCheckedIn(data);
        };

        filterButton.addEventListener('click', fetchAttendance);

        fetchAttendance();
        fetchCheckedIn();
    </script>
</x-app-layout>
