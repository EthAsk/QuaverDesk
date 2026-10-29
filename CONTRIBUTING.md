# Contributing

Thanks for helping improve QuaverDesk. Bug reports and suggested changes are
welcome. Every pull request is reviewed by Ethan Askew T/A Quaver Desk, who
decides whether to accept it; nothing is merged without that review. For
larger changes, please open an issue first so we can agree the approach.

Accepted changes are added to our own development copy and appear here in the
next release, so your pull request may be closed once its change is included
rather than merged directly.

## Licence for your contribution — please read

This project is source-available under the Elastic License 2.0 and is also sold
under paid licences. To keep that possible, every contribution must be made on
these terms:

> By submitting a contribution (code, documentation or other material) to this
> repository, you:
>
> 1. confirm that you wrote it, or otherwise have the right to submit it, and
>    that it does not knowingly infringe anyone else's rights;
> 2. grant Ethan Askew T/A Quaver Desk a perpetual, worldwide, non-exclusive, royalty-free,
>    irrevocable licence to use, copy, modify, distribute, sublicense and
>    relicense your contribution, under any terms, including the Elastic License
>    2.0 and commercial licences; and
> 3. understand that your contribution is provided "as is", without warranty,
>    and that you keep the copyright in it.

To confirm, add a `Signed-off-by` line to each commit (`git commit -s`), using
your real name and email. Pull requests without sign-off cannot be merged.

## Ground rules

- Do not include secrets, credentials or real customer data in issues, commits
  or tests.
- Security issues go through [SECURITY.md](SECURITY.md), never public issues.
- Pull requests that remove, weaken or bypass licence-key checks will not be
  accepted.
- Run `php artisan test` before opening a pull request.
