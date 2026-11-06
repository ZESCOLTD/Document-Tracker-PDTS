<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Task Notification</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f2f3f5;">
<table align="center" cellpadding="0" cellspacing="0" width="100%" style="padding: 40px 0;">
    <tr>
        <td align="center">
            <table cellpadding="0" cellspacing="0" width="600" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                <!-- Header -->
                <tr>
                    <td align="center" bgcolor="#28a745" style="padding: 20px; color: #ffffff; font-size: 20px; font-weight: bold;">
                        Task Notification - Action Required
                    </td>
                </tr>

                <!-- Body -->
                <tr>
                    <td style="padding: 30px; color: #333333; font-size: 14px;">
                        <p style="margin: 0 0 20px;">Greetings, {{$task->responsible_name ?? "Not yet assigned"}}</p>

                        <p style="margin: 0 0 20px;">Please note that a task titled <strong>{{$task->name}}</strong> with the following details is in your inbox awaiting review:</p>

                        <table cellpadding="5" cellspacing="0" width="100%" style="font-size: 14px;">
                            {{--                            <tr><td style="font-weight: bold;">REQ ID:</td><td>{{$nonConformance->request->code}}</td></tr>--}}
                            <tr><td style="font-weight: bold;">Reference No:</td><td>{{$task->reference ?? ""}}</td></tr>
                            <tr><td style="font-weight: bold;">Title:</td><td>{{$task->name}}</td></tr>
                            <tr>
                                <td style="font-weight: bold; vertical-align: top;">Description:</td>
                                <td>
                                    {{$task->description}}
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight: bold; vertical-align: top;">Task Status:</td>
                                <td style="font-weight: bold;">
                                    {{ \App\Enums\TaskStatus::from((int) $task->task_status)->label }}
                                </td>
                            </tr>

                            <tr><td style="font-weight: bold;"></td><td></td></tr>

                            <tr><td style="font-weight: bold;">Creator:</td><td>{{$task->creator_name ?? "Not yet assigned"}}</td></tr>
                            <tr><td style="font-weight: bold;">Responsible:</td><td>{{$task->responsible_name ?? "Not yet assigned"}}</td></tr>

                            <tr><td style="font-weight: bold;"></td><td></td></tr>

                            <tr><td style="font-weight: bold;">Due At:</td><td>{{!is_null($task->due_at) ? date("d M Y", strtotime($task->due_at)) : " "}}</td></tr>
                            <tr><td style="font-weight: bold;">Creation Date:</td><td>{{!is_null($task->created_at) ? date("d M Y", strtotime($task->created_at)) : " "}}</td></tr>
                        </table>

                        <p style="margin: 30px 0 10px;">To review, please login:</p>

                        <!-- Button -->
                        <div style="text-align: center;">
                            <a href="{{ route('notifications.index') }}" style="background-color: #28a745; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block; font-weight: bold;">
                                Login
                            </a>
                        </div>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="padding: 20px; font-size: 12px; text-align: center; color: #888888; border-top: 1px solid #e0e0e0;">
                        &copy; {{date('Y')}} {{config('app.name')}} | <a href="{{route('login')}}" style="color: #007bff; text-decoration: none;">Visit our {{config('app.name')}}</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
