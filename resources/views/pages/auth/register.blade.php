<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />


        <form id="registrationForm" method="POST" action="{{ route('register') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Man Number -->
            <flux:input
                name="man_no"
                :label="__('Man Number')"
                :value="old('man_no')"
                type="text"
                required
                autofocus
                :placeholder="__('Enter your Man Number')"
                id="man_no"
            />

            <!-- Work Email -->
            <flux:input
                name="email"
                :label="__('Work Email')"
                :value="old('email')"
                type="email"
                required
                :placeholder="__('username@zesco.co.zm')"
                id="email"
            />

            <!-- Extra Fields (hidden initially) -->
            <div id="extra-fields" class="flex flex-col gap-6" style="display:none;">
                <flux:input
                    name="name"
                    :label="__('Full Name')"
                    type="text"
                    readonly
                    id="emp_name"
                />
                <flux:input
                    name="directorate"
                    :label="__('Directorate')"
                    type="text"
                    readonly
                    id="emp_directorate"
                />
                <flux:input
                    name="job_title"
                    :label="__('Job Title')"
                    type="text"
                    readonly
                    id="emp_job_title"
                />

                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    viewable
                    id="password"
                />
                <div class="progress mt-2" style="height:5px;">
                    <div id="passwordStrength" class="progress-bar"></div>
                </div>

                <flux:input
                    name="password_confirmation"
                    :label="__('Confirm Password')"
                    type="password"
                    required
                    viewable
                    id="confirm_password"
                />
                <small id="matchLabel" class="fw-semibold"></small>
            </div>
            <div id="messageBox" class="text-center text-sm mb-2"></div>

            <flux:button type="button" variant="primary" class="w-full" id="mainBtn">
    <span id="btnContent">
        <i class="bi bi-search"></i>
        <span id="btnText">{{ __('Verify Employee') }}</span>
    </span>

                <span id="btnLoader" style="display:none;">
        <span class="spinner-border spinner-border-sm"></span>
        Verifying...
    </span>
            </flux:button>

        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            let isVerified = false;
            const form = document.getElementById('registrationForm');
            const mainBtn = document.getElementById('mainBtn');
            const btnText = document.getElementById('btnText');
            const btnLoader = document.getElementById('btnLoader');
            const extraFields = document.getElementById('extra-fields');
            const messageBox = document.getElementById('messageBox');
            const manNoInput = document.getElementById('man_no');
            const emailInput = document.getElementById('email');
            const csrfToken = form.querySelector('input[name="_token"]')?.value ?? '';
            const verifyUrl = @json(route('register.verify', [], false));

            function showMessage(message, type = 'error') {
                messageBox.textContent = message;
                messageBox.className = 'text-center text-sm mb-2';
                if (message) {
                    messageBox.classList.add(type === 'success' ? 'text-green-700' : 'text-red-600');
                }
            }

            function resetVerification() {
                if (isVerified) {
                    isVerified = false;
                    extraFields.style.display = 'none';
                    btnText.textContent = 'Verify Employee';
                    document.getElementById('password').value = '';
                    document.getElementById('confirm_password').value = '';
                    document.getElementById('passwordStrength').style.width = '0%';
                    document.getElementById('matchLabel').textContent = '';
                }
            }

            manNoInput.addEventListener('input', resetVerification);
            emailInput.addEventListener('input', resetVerification);

            form.addEventListener('submit', event => {
                if (! isVerified) {
                    event.preventDefault();
                }
            });

            mainBtn.addEventListener('click', async () => {
                if (isVerified) {
                    form.requestSubmit();
                    return;
                }

                const man_no = manNoInput.value.trim();
                const email = emailInput.value.trim();

                if (! man_no || ! email) {
                    showMessage('Please enter Man Number and Work Email.');
                    return;
                }

                mainBtn.disabled = true;
                document.getElementById('btnContent').style.display = 'none';
                btnLoader.style.display = 'inline-flex';
                showMessage('');

                try {
                    const response = await fetch(verifyUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({man_no, email}),
                    });

                    const result = await response.json().catch(() => ({}));
                    if (response.status === 419) {
                        throw new Error('Your session expired. Reload this page and try again.');
                    }
                    if (! response.ok || ! result.success) {
                        throw new Error(result.message || 'Employee verification failed. Try again.');
                    }

                    document.getElementById('emp_name').value = result.user.name ?? '';
                    document.getElementById('emp_directorate').value = result.user.directorate ?? '';
                    document.getElementById('emp_job_title').value = result.user.job_title ?? '';
                    extraFields.style.display = 'flex';
                    btnText.textContent = 'Create Account';
                    showMessage('Employee verified successfully.', 'success');
                    isVerified = true;
                } catch (error) {
                    showMessage(error.message || 'System error. Try again.');
                } finally {
                    mainBtn.disabled = false;
                    btnLoader.style.display = 'none';
                    document.getElementById('btnContent').style.display = 'inline-flex';
                }
            });

            function updatePasswordFeedback() {
                const pwd = document.getElementById('password').value;
                const conf = document.getElementById('confirm_password').value;
                let score = 0;
                if (pwd.length >= 8) score += 20;
                if (/[A-Z]/.test(pwd)) score += 20;
                if (/[0-9]/.test(pwd)) score += 20;
                if (/[^A-Za-z0-9]/.test(pwd)) score += 40;

                const strength = document.getElementById('passwordStrength');
                strength.style.width = score + '%';
                strength.classList.toggle('bg-danger', score < 50);
                strength.classList.toggle('bg-success', score >= 80);

                const matchLabel = document.getElementById('matchLabel');
                if (conf !== '') {
                    matchLabel.textContent = pwd === conf ? 'Match' : 'No match';
                    matchLabel.style.color = pwd === conf ? 'green' : 'red';
                } else {
                    matchLabel.textContent = '';
                }
            }

            document.getElementById('password').addEventListener('input', updatePasswordFeedback);
            document.getElementById('confirm_password').addEventListener('input', updatePasswordFeedback);
        });

    </script>
</x-layouts::auth>
